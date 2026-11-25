<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Activity;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreMerchantRequest;
use App\Http\Requests\V1\UpdateMerchantRequest;
use App\Http\Resources\V1\MerchantResource;
use App\Models\BillingPlan;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Services\Labels\LabelSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MerchantController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Merchant::class);

        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string'],
            'source' => ['nullable', 'string', 'in:backoffice,signup'],
            'activity' => ['nullable', Rule::enum(Activity::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            // Page Clients : zone de ramassage, position connue ou non, tri, résumé
            'zone_id' => ['nullable', 'integer'],
            'located' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:name,orders,created_at'],
            'dir' => ['nullable', 'in:asc,desc'],
            'summary' => ['nullable', 'boolean'],
        ]);
        $dir = $request->input('dir') === 'desc' ? 'desc' : 'asc';

        $merchants = Merchant::query()
            ->with(['pickupZone', 'activityRows', 'billingPlans' => fn ($q) => $q->activeOn(now())->with('couriers.user')])
            ->withCount('orders')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('activity'), fn ($q) => $q->withActivity([$request->string('activity')->toString()]))
            // Inscrits en ligne
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source'))->when(! $request->filled('sort'), fn ($q) => $q->latest()))
            ->when($request->filled('zone_id'), fn ($q) => $q->where('pickup_zone_id', $request->integer('zone_id')))
            ->when($request->filled('located'), fn ($q) => $request->boolean('located') ? $q->whereNotNull('pickup_lat') : $q->whereNull('pickup_lat'))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.mb_strtolower($request->string('search')).'%';
                $q->where(fn ($q) => $q->whereRaw('LOWER(business_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(contact_name) LIKE ?', [$term])
                    ->orWhere('phone', 'like', $term));
            })
            ->when($request->input('sort') === 'orders', fn ($q) => $q->orderBy('orders_count', $dir))
            ->when($request->input('sort') === 'created_at', fn ($q) => $q->orderBy('created_at', $dir)->orderBy('id', $dir))
            ->orderBy('business_name', $request->input('sort') === 'name' ? $dir : 'asc')
            ->paginate(min(max($request->integer('per_page', 50), 1), 500));

        $collection = MerchantResource::collection($merchants);

        // Résumé de la page Clients : chaque case filtre la liste
        if ($request->boolean('summary')) {
            $collection->additional(['summary' => [
                'merchants' => Merchant::query()->count(),
                'active' => Merchant::query()->where('status', 'active')->count(),
                'suspended' => Merchant::query()->where('status', '!=', 'active')->count(),
                'signups_new' => Merchant::query()->where('source', 'signup')->where('created_at', '>=', now()->subDays(7))->count(),
                'without_location' => Merchant::query()->where('status', 'active')->whereNull('pickup_lat')->count(),
            ]]);
        }

        return $collection;
    }

    public function store(StoreMerchantRequest $request): JsonResponse
    {
        $data = $request->validated();

        $merchant = DB::transaction(function () use ($data, $request) {
            $merchant = Merchant::create(Arr::except($data, ['owner', 'activities']));
            $merchant->syncActivities($data['activities'] ?? [Activity::Ecommerce]);
            self::trackLocationSource($merchant);

            if (! empty($data['owner'])) {
                $owner = User::create([
                    'company_id' => $request->user()->company_id,
                    'merchant_id' => $merchant->id,
                    'name' => $data['owner']['name'],
                    'phone' => $data['owner']['phone'],
                    'email' => $data['owner']['email'] ?? null,
                    'password' => $data['owner']['password'],
                ]);
                $owner->assignRole(Role::MerchantOwner->value);
            }

            return $merchant;
        });

        return MerchantResource::make($merchant->load(['pickupZone', 'users.roles', 'billingPlans' => fn ($q) => $q->activeOn(now())->with('couriers.user')]))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Merchant $merchant): MerchantResource
    {
        Gate::authorize('view', $merchant);

        return MerchantResource::make($merchant->load(['pickupZone', 'users.roles', 'billingPlans' => fn ($q) => $q->activeOn(now())->with('couriers.user')])->loadCount('orders'));
    }

    public function update(UpdateMerchantRequest $request, Merchant $merchant): MerchantResource
    {
        $data = $request->validated();
        if (array_key_exists('label_settings', $data)) {
            $data['label_settings'] = LabelSettings::clean((array) ($data['label_settings'] ?? [])) ?: null;
        }
        $merchant->update(Arr::except($data, 'activities'));
        if (isset($data['activities'])) {
            // Activité retirée : seulement sans course ni formule (l'historique reste rattaché)
            $kept = array_map(fn ($a) => $a instanceof Activity ? $a->value : $a, $data['activities']);
            foreach ($merchant->activityList() as $activity) {
                if ($request->user()->seesActivity($activity) && ! in_array($activity->value, $kept, true)
                    && (Order::withoutGlobalScopes()->where('merchant_id', $merchant->id)->where('activity', $activity->value)->exists()
                        || BillingPlan::withoutGlobalScopes()->where('merchant_id', $merchant->id)->where('activity', $activity->value)->exists())) {
                    throw new BusinessRuleException("Ce client a déjà des courses ou une formule {$activity->label()} : l'activité ne peut pas être retirée.", 'activities');
                }
            }
            // Activités hors du périmètre de l'utilisateur : gardées telles quelles
            $merchant->syncActivities([
                ...array_filter($merchant->activityList(), fn (Activity $a) => ! $request->user()->seesActivity($a)),
                ...$data['activities'],
            ]);
        }
        self::trackLocationSource($merchant);

        // Un client suspendu ne peut plus se connecter
        if ($merchant->wasChanged('status') && ! $merchant->isActive()) {
            foreach ($merchant->users as $user) {
                $user->tokens()->delete();
            }
        }

        return MerchantResource::make($merchant->load(['pickupZone', 'users.roles', 'billingPlans' => fn ($q) => $q->activeOn(now())->with('couriers.user')]));
    }

    /**
     * Position saisie ou retirée par l'agence (fiche ou API) : source et date.
     */
    private static function trackLocationSource(Merchant $merchant): void
    {
        if (! $merchant->wasChanged(['pickup_lat', 'pickup_lng']) && ! $merchant->wasRecentlyCreated) {
            return;
        }

        $merchant->forceFill($merchant->hasPickupLocation()
            ? ['pickup_location_source' => Merchant::LOCATION_STAFF, 'pickup_located_at' => now(), 'pickup_location_accuracy' => null]
            : ['pickup_location_source' => null, 'pickup_located_at' => null, 'pickup_location_accuracy' => null])->save();
    }
}
