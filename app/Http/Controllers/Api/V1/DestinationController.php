<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AssignmentType;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DestinationResource;
use App\Models\Company;
use App\Models\Destination;
use App\Models\DestinationLocation;
use App\Models\Order;
use App\Services\Destinations\DestinationImporter;
use App\Services\Destinations\DestinationLocations;
use App\Services\Destinations\DestinationPhones;
use App\Services\Destinations\Destinations;
use App\Services\Destinations\DestinationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Annuaire des destinations (partagé par tous les clients) : liste et corrections par l'équipe, suggestions
 * pendant la saisie d'une course, import, fusion des doublons, position GPS.
 */
class DestinationController extends Controller
{
    public function __construct(
        private readonly Destinations $destinations,
        private readonly DestinationLocations $locations,
        private readonly DestinationSettings $settings,
    ) {}

    /** Suggestions pendant la saisie d'une course (équipe ; clients si l'administrateur le permet) */
    public function suggest(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $data = $request->validate(['q' => ['required', 'string', 'max:100'], 'merchant_id' => ['nullable', 'integer']]);
        $settings = $this->settings->for(Company::query()->find($user->company_id));
        $staff = $user->merchant_id === null && ! $user->isCourier();
        abort_unless($settings['enabled'] && ($staff || ($user->merchant_id !== null && $settings['clients_see'])), 403);
        $merchantId = $user->merchant_id ?? ($data['merchant_id'] ?? null);

        return DestinationResource::collection($this->destinations->search($user->company_id, $data['q'], $merchantId, $staff));
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'filtre' => ['nullable', Rule::in(['all', 'check', 'no_location', 'located', 'conflict', 'pending', 'no_zone'])],
            'zone_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $companyId = $request->user()->company_id;
        $filters = fn ($q, ?string $f) => match ($f) {
            'check' => $q->where('phone_check', true),
            'no_location' => $q->whereNull('lat'),
            'located' => $q->whereNotNull('lat'),
            'conflict' => $q->where('location_conflict', true),
            'pending' => $q->where('status', 'pending'),
            'no_zone' => $q->whereNull('zone_id'),
            default => $q,
        };
        $words = array_filter(explode(' ', Destination::normalize($data['search'] ?? '')), fn ($w) => mb_strlen($w) >= 2);

        $page = Destination::query()->forCompany($companyId)->with(['zone:id,name', 'locator:id,name'])
            ->tap(fn ($q) => $filters($q, $data['filtre'] ?? null))
            ->when($data['zone_id'] ?? null, fn ($q, $z) => $q->where('zone_id', $z))
            ->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->where('search', 'like', '%'.$word.'%');
                }
            })
            ->orderByDesc('location_conflict')->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->orderByDesc('deliveries_count')->orderBy('name')
            ->paginate($data['per_page'] ?? 50);

        $base = fn () => Destination::query()->forCompany($companyId);

        return response()->json([
            'data' => DestinationResource::collection($page->items())->resolve($request),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'counts' => collect(['all', 'check', 'no_location', 'located', 'conflict', 'pending', 'no_zone'])
                ->mapWithKeys(fn ($f) => [$f => $filters($base(), $f)->count()]),
        ]);
    }

    public function show(Request $request, Destination $destination): DestinationResource
    {
        $this->authorizeStaff($request);

        return DestinationResource::make($destination->load(['zone:id,name', 'locator:id,name', 'locations.user:id,name']));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeStaff($request, true);
        $destination = Destination::create([...$this->validated($request), 'company_id' => $request->user()->company_id, 'source' => 'staff', 'created_by' => $request->user()->id]);

        return DestinationResource::make($destination->load('zone:id,name'))->response()->setStatusCode(201);
    }

    /** Correction par l'équipe : un numéro vérifié enlève « à vérifier » si l'équipe le dit */
    public function update(Request $request, Destination $destination): DestinationResource
    {
        $this->authorizeStaff($request, true);
        $destination->update($this->validated($request, true));

        return DestinationResource::make($destination->fresh()->load(['zone:id,name', 'locator:id,name', 'locations.user:id,name']));
    }

    public function destroy(Request $request, Destination $destination): JsonResponse
    {
        $this->authorizeStaff($request, true);
        $destination->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** Doublons : les autres fiches sont regroupées dans celle-ci (numéros, informations, courses, positions) */
    public function merge(Request $request, Destination $destination): DestinationResource
    {
        $this->authorizeStaff($request, true);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:20'], 'ids.*' => ['integer', 'different:'.$destination->id]]);

        DB::transaction(function () use ($destination, $data) {
            $others = Destination::query()->forCompany($destination->company_id)->whereIn('id', $data['ids'])->whereKeyNot($destination->id)->get();
            foreach ($others as $other) {
                $phones = array_values(array_unique([...$destination->phones(), ...$other->phones()]));
                $destination->fill(['phone' => $phones[0] ?? null, 'phone2' => $phones[1] ?? null, 'phone3' => $phones[2] ?? null]);
                foreach (['acronym', 'zone_id', 'district', 'address', 'landmark', 'email', 'sector', 'city'] as $field) {
                    $destination->{$field} = filled($destination->{$field}) ? $destination->{$field} : $other->{$field};
                }
                if (! $destination->hasLocation() && $other->hasLocation()) {
                    $destination->fill($other->only(['lat', 'lng', 'location_accuracy', 'location_source', 'located_at', 'located_by']));
                }
                $destination->phones_raw = mb_substr(trim(implode(' / ', array_filter([$destination->phones_raw, $other->phones_raw]))), 0, 255) ?: null;
                $destination->phone_check = $destination->phone_check || $other->phone_check;
                $destination->deliveries_count += $other->deliveries_count;
                Order::withoutGlobalScopes()->where('destination_id', $other->id)->update(['destination_id' => $destination->id]);
                DestinationLocation::where('destination_id', $other->id)->update(['destination_id' => $destination->id]);
                $other->delete();
            }
            $destination->status = 'active';
            $destination->save();
        });

        return DestinationResource::make($destination->fresh()->load(['zone:id,name', 'locator:id,name', 'locations.user:id,name']));
    }

    /** Import : essai (rapport) puis enregistrement */
    public function import(Request $request, DestinationImporter $importer): JsonResponse
    {
        $this->authorizeStaff($request, true);
        $request->validate(['file' => ['required', 'file', 'max:20480'], 'dry_run' => ['sometimes', 'boolean']], [], ['file' => 'fichier']);
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->extension());
        if (! in_array($extension, ['sql', 'csv', 'txt'], true)) {
            throw new BusinessRuleException('Fichier attendu : export SQL (.sql) ou CSV (.csv).', 'file');
        }

        return response()->json(['data' => $importer->import($request->user()->company, (string) file_get_contents($file->getRealPath()), $extension === 'sql' ? 'sql' : 'csv',
            $request->boolean('dry_run', true), $request->user()->id)]);
    }

    /** Position posée par l'équipe (carte), ou position relevée choisie pour trancher un écart */
    public function locate(Request $request, Destination $destination): DestinationResource
    {
        $this->authorizeStaff($request, true);
        $data = $request->validate([
            'location_id' => ['nullable', 'integer'],
            'lat' => ['required_without:location_id', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['required_without:location_id', 'nullable', 'numeric', 'between:-180,180'],
            'clear_conflict' => ['sometimes', 'boolean'],
        ]);
        if ($request->boolean('clear_conflict') && empty($data['location_id']) && empty($data['lat'])) {
            $destination->update(['location_conflict' => false]);
        } else {
            $point = isset($data['location_id']) ? DestinationLocation::where('destination_id', $destination->id)->findOrFail($data['location_id']) : null;
            $this->locations->confirm($destination, $request->user(), (float) ($point->lat ?? $data['lat']), (float) ($point->lng ?? $data['lng']), $point?->accuracy, null, true);
        }

        return DestinationResource::make($destination->fresh()->load(['zone:id,name', 'locator:id,name', 'locations.user:id,name']));
    }

    /** Livreur sur place : sa position devient celle de l'adresse de la course */
    public function locateOrder(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        $mine = $user->isCourier() && $order->assignments()->where('courier_id', $user->courier?->id)->where('type', AssignmentType::Delivery->value)->exists();
        abort_unless($mine || ($user->merchant_id === null && $user->can(Permission::OrdersDispatch->value)), 403);

        $result = $this->locations->fromOrder($order, $user, (float) $data['lat'], (float) $data['lng'], $data['accuracy'] ?? null);

        return response()->json(['data' => [
            'outcome' => $result['outcome'] ?? 'order',
            'distance' => $result['distance'] ?? null,
            'message' => match ($result['outcome'] ?? 'order') {
                'replaced' => 'Position enregistrée : elle servira aux prochaines courses.',
                'kept' => 'Merci : la position connue est confirmée.',
                'conflict' => "Position notée : elle est à {$result['distance']} m de celle connue, l'équipe va vérifier.",
                default => 'Position enregistrée pour cette course.',
            },
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $companyId = $request->user()->company_id;
        $sometimes = $partial ? 'sometimes' : 'nullable';
        $data = $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'acronym' => [$sometimes, 'nullable', 'string', 'max:60'],
            'city' => [$sometimes, 'nullable', 'string', 'max:80'],
            'zone_id' => [$sometimes, 'nullable', 'integer', Rule::exists('zones', 'id')->where('company_id', $companyId)],
            'district' => [$sometimes, 'nullable', 'string', 'max:120'],
            'address' => [$sometimes, 'nullable', 'string', 'max:2000'],
            'landmark' => [$sometimes, 'nullable', 'string', 'max:255'],
            'phone' => [$sometimes, 'nullable', 'string', 'max:30'],
            'phone2' => [$sometimes, 'nullable', 'string', 'max:30'],
            'phone3' => [$sometimes, 'nullable', 'string', 'max:30'],
            'phone_check' => ['sometimes', 'boolean'],
            'email' => [$sometimes, 'nullable', 'email', 'max:190'],
            'sector' => [$sometimes, 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['active', 'pending'])],
        ]);
        // Seuls des numéros au bon format sont enregistrés
        foreach (['phone', 'phone2', 'phone3'] as $field) {
            if (filled($data[$field] ?? null)) {
                $data[$field] = DestinationPhones::valid($data[$field])
                    ?? throw new BusinessRuleException('Numéro au format à 10 chiffres attendu (ex. : 07 48 20 86 66, 27 20 32 07 58).', $field);
            }
        }

        return $data;
    }

    private function authorizeStaff(Request $request, bool $manage = false): void
    {
        $user = $request->user();
        abort_unless($user->merchant_id === null && ! $user->isCourier()
            && $user->can(($manage ? Permission::DestinationsManage : Permission::MerchantsView)->value), 403);
    }
}
