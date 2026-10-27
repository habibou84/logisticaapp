<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AssignmentStatus;
use App\Enums\VehicleType;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\VehicleResource;
use App\Models\Courier;
use App\Models\OrderAssignment;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleCheck;
use App\Models\VehicleDocument;
use App\Models\VehicleLease;
use App\Models\VehicleLeasePayment;
use App\Models\VehicleLog;
use App\Services\Photos\DocumentPhoto;
use App\Services\Vehicles\Fleet;
use App\Services\Vehicles\VehicleCatalog;
use App\Services\Vehicles\VehicleLeases;
use App\Services\Vehicles\VehicleLogs;
use App\Services\Vehicles\VehicleSettings;
use App\Services\Vehicles\VehicleStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Véhicules côté équipe : parc, fiche (papiers, entretien, carnet, historique, location-vente, coûts),
 * remises aux livreurs, échéances et statistiques.
 */
class VehicleController extends Controller
{
    public function __construct(
        private readonly Fleet $fleet,
        private readonly VehicleLogs $logs,
        private readonly VehicleLeases $leases,
        private readonly VehicleSettings $settings,
    ) {}

    /** Listes (papiers, états, contrôles…) et réglages utiles aux écrans */
    public function catalog(Request $request): JsonResponse
    {
        return response()->json(['data' => [...VehicleCatalog::all(), 'settings' => $this->settings->for($request->user()->company)]]);
    }

    /** Échéances dépassées et véhicules immobilisés (badge du menu) */
    public function counts(Request $request): JsonResponse
    {
        $alerts = collect($this->fleet->alerts($request->user()->company_id));

        return response()->json(['data' => ['urgent' => $alerts->where('state', 'expired')->count(), 'total' => $alerts->count()]]);
    }

    public function index(Request $request): JsonResponse
    {
        $vehicles = Vehicle::query()->with(VehicleResource::RELATIONS)->orderByRaw("CASE WHEN status = 'sold' THEN 1 ELSE 0 END")->orderBy('plate')->get();
        $alerts = $this->fleet->alerts($request->user()->company_id);
        $since = now()->subDays(30)->toDateString();

        $rows = $vehicles->map(function (Vehicle $v) use ($alerts, $since) {
            $watch = collect($alerts)->where('vehicle_id', $v->id)->first();
            $costs = $this->fleet->costs($v, $since);
            $courses = $this->courses($v, now()->subDays(30), now());

            return [
                ...VehicleResource::make($v)->resolve(),
                'watch' => $watch,
                'problems' => $this->fleet->problems($v),
                'cost_per_course' => $courses ? (int) round($costs['total'] / $courses) : null,
            ];
        });
        $active = $vehicles->where('status', '!=', 'sold');
        $perCourse = $rows->pluck('cost_per_course')->filter();

        return response()->json([
            'data' => $rows->values(),
            'alerts' => $alerts,
            'kpis' => [
                'total' => $active->count(),
                'by_type' => $active->countBy(fn ($v) => $v->type->value),
                'active' => $active->where('status', 'active')->count(),
                'own' => $active->where('ownership', 'courier')->count(),
                'stopped' => $active->whereIn('status', ['broken', 'maintenance', 'immobilized'])->count(),
                'deadlines' => count(array_filter($alerts, fn ($a) => in_array($a['kind'], ['document', 'license', 'maintenance'], true))),
                'expired' => count(array_filter($alerts, fn ($a) => $a['state'] === 'expired' && $a['kind'] !== 'status')),
                'cost_per_course' => $perCourse->isNotEmpty() ? (int) round($perCourse->avg()) : null,
            ],
            'couriers' => Courier::query()->with('user')->whereHas('user', fn ($q) => $q->where('status', 'active'))->get()
                ->map(fn (Courier $c) => ['id' => $c->id, 'name' => $c->user->name, 'vehicle_id' => $vehicles->firstWhere('courier_id', $c->id)?->id])->sortBy('name')->values(),
        ]);
    }

    /** Courses terminées par ceux qui avaient le véhicule, sur la période */
    private function courses(Vehicle $vehicle, $from, $to): int
    {
        $count = 0;
        foreach (VehicleAssignment::where('vehicle_id', $vehicle->id)->where('starts_at', '<=', $to)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $from))->get() as $a) {
            $count += OrderAssignment::where('courier_id', $a->courier_id)->where('status', AssignmentStatus::Completed->value)
                ->whereBetween('completed_at', [max($a->starts_at, $from), $a->ends_at ? min($a->ends_at, $to) : $to])->count();
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(Request $request, ?Vehicle $vehicle = null): array
    {
        $company = $request->user()->company_id;

        return [
            'type' => [$vehicle ? 'sometimes' : 'required', Rule::enum(VehicleType::class)],
            'plate' => [$vehicle ? 'sometimes' : 'required', 'string', 'max:30',
                Rule::unique('vehicles', 'plate')->where('company_id', $company)->ignore($vehicle?->id)],
            'brand' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'year' => ['nullable', 'integer', 'between:1950,2100'],
            'color' => ['nullable', 'string', 'max:30'],
            'vin' => ['nullable', 'string', 'max:60'],
            'capacity_parcels' => ['nullable', 'integer', 'between:1,10000'],
            'capacity_kg' => ['nullable', 'integer', 'between:1,100000'],
            'has_box' => ['sometimes', 'boolean'],
            'ownership' => ['sometimes', Rule::in(array_keys(Vehicle::OWNERSHIPS))],
            'owner_courier_id' => ['nullable', 'integer', Rule::exists('couriers', 'id')->where('company_id', $company)],
            'status' => ['sometimes', Rule::in(array_keys(Vehicle::STATUSES))],
            'odometer' => ['nullable', 'integer', 'between:0,10000000'],
            'purchase_price' => ['nullable', 'integer', 'min:0'],
            'purchased_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'courier_id' => ['nullable', 'integer', Rule::exists('couriers', 'id')->where('company_id', $company)],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules($request), [], ['plate' => 'immatriculation']);
        $vehicle = $this->fleet->create($request->user(), $data);

        return response()->json(['data' => VehicleResource::make($vehicle->load(VehicleResource::RELATIONS))], 201);
    }

    public function update(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate($this->rules($request, $vehicle), [], ['plate' => 'immatriculation']);
        unset($data['courier_id']);
        $status = $data['status'] ?? null;
        unset($data['status']);
        if (isset($data['odometer'])) {
            $vehicle->readOdometer((int) $data['odometer']);
            unset($data['odometer']);
        }
        $vehicle->fill($data)->save();
        if ($status && $status !== $vehicle->status) {
            $this->fleet->setStatus($vehicle, $status);
        }

        return $this->show($request, $vehicle->fresh());
    }

    public function photo(Request $request, Vehicle $vehicle): JsonResponse
    {
        $request->validate(['photo' => ['required', 'image', 'max:8192']]);
        $vehicle->forceFill(['photo_path' => $request->file('photo')->store("vehicles/{$vehicle->id}", 'local')])->save();

        return $this->show($request, $vehicle);
    }

    public function showPhoto(Vehicle $vehicle): StreamedResponse
    {
        abort_unless($vehicle->photo_path, 404);

        return Storage::disk('local')->response($vehicle->photo_path);
    }

    /** Fiche complète : papiers, entretien, carnet, historique, états des lieux, location, coûts */
    public function show(Request $request, Vehicle $vehicle): JsonResponse
    {
        $vehicle->load(VehicleResource::RELATIONS);
        $s = $this->settings->for($request->user()->company);
        $documents = VehicleDocument::where('vehicle_id', $vehicle->id)->orderByDesc('ends_on')->orderByDesc('id')->get();
        $latest = $this->fleet->latestDocuments($vehicle)->pluck('id')->all();
        $leases = VehicleLease::where('vehicle_id', $vehicle->id)->with(['courier.user', 'payments'])->latest('id')->get();
        $courses = $this->courses($vehicle, $vehicle->created_at->copy()->subYears(10), now());
        $costs = $this->fleet->costs($vehicle);
        $km = max(0, $vehicle->odometer - (int) (VehicleAssignment::where('vehicle_id', $vehicle->id)->min('odometer_start') ?? $vehicle->odometer));

        return response()->json(['data' => [
            ...VehicleResource::make($vehicle)->resolve(),
            'problems' => $this->fleet->problems($vehicle),
            'documents' => $documents->map(fn (VehicleDocument $d) => $this->document($d, $s, in_array($d->id, $latest, true)))->values(),
            'maintenance' => $this->fleet->maintenance($vehicle, $s),
            'logs' => VehicleLog::where('vehicle_id', $vehicle->id)->with('courier.user')->orderByDesc('done_on')->orderByDesc('id')->limit(200)->get()
                ->map(fn (VehicleLog $l) => $this->log($l))->values(),
            'assignments' => VehicleAssignment::where('vehicle_id', $vehicle->id)->with('courier.user')->latest('starts_at')->limit(100)->get()
                ->map(fn (VehicleAssignment $a) => ['id' => $a->id, 'kind' => $a->kind, 'courier' => $a->courier?->user?->name, 'starts_at' => $a->starts_at, 'ends_at' => $a->ends_at,
                    'odometer_start' => $a->odometer_start, 'odometer_end' => $a->odometer_end])->values(),
            'checks' => VehicleCheck::where('vehicle_id', $vehicle->id)->with('courier.user')->latest('id')->limit(60)->get()
                ->map(fn (VehicleCheck $c) => $this->check($c))->values(),
            'leases' => $leases->map(fn (VehicleLease $l) => $this->lease($l))->values(),
            'costs' => $costs,
            'courses' => $courses,
            'cost_per_course' => $courses ? (int) round($costs['total'] / $courses) : null,
            'cost_per_km' => $km ? (int) round($costs['total'] / $km) : null,
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(VehicleDocument $d, array $s, bool $current = true): array
    {
        return [
            'id' => $d->id, 'kind' => $d->kind, 'kind_label' => VehicleCatalog::DOCUMENTS[$d->kind]['label'] ?? $d->kind, 'icon' => VehicleCatalog::DOCUMENTS[$d->kind]['icon'] ?? '📄',
            'label' => $d->label, 'reference' => $d->reference, 'issuer' => $d->issuer, 'starts_on' => $d->starts_on?->toDateString(), 'ends_on' => $d->ends_on?->toDateString(),
            'cost' => $d->cost, 'has_file' => $d->file_path !== null, 'has_original' => $d->original_path !== null, 'current' => $current, ...$this->fleet->documentState($d, $s),
            'vehicle_id' => $d->vehicle_id, 'courier_id' => $d->courier_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function log(VehicleLog $l): array
    {
        return [
            'id' => $l->id, 'kind' => $l->kind, 'kind_label' => VehicleLog::KINDS[$l->kind], 'icon' => VehicleLog::ICONS[$l->kind], 'done_on' => $l->done_on->toDateString(),
            'odometer' => $l->odometer, 'label' => $l->label, 'garage' => $l->garage, 'amount' => $l->amount, 'liters' => $l->liters, 'paid_by' => $l->paid_by,
            'courier' => $l->courier?->user?->name, 'courier_share' => $l->courier_share, 'charged' => $l->charged, 'plan_keys' => $l->plan_keys,
            'immobilized_days' => $l->immobilized_days, 'field_event_id' => $l->field_event_id, 'photos' => count($l->photos ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function check(VehicleCheck $c): array
    {
        return [
            'id' => $c->id, 'moment' => $c->moment, 'courier' => $c->courier?->user?->name, 'odometer' => $c->odometer, 'fuel' => $c->fuel,
            'fuel_label' => VehicleCatalog::FUEL_LEVELS[$c->fuel] ?? null, 'items' => $c->items, 'problems' => $c->problems, 'note' => $c->note,
            'photos' => count($c->photos ?? []), 'gps_km' => $c->gps_km, 'created_at' => $c->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lease(VehicleLease $l): array
    {
        return [
            'id' => $l->id, 'kind' => $l->kind, 'courier' => ['id' => $l->courier_id, 'name' => $l->courier?->user?->name], 'amount' => $l->amount, 'period' => $l->period,
            'days_per_week' => $l->days_per_week, 'total_amount' => $l->total_amount, 'starts_on' => $l->starts_on->toDateString(), 'payment' => $l->payment,
            'status' => $l->status, 'notes' => $l->notes, ...$this->leases->balance($l),
            'payments' => $l->payments->take(100)->map(fn (VehicleLeasePayment $p) => ['id' => $p->id, 'amount' => $p->amount, 'paid_on' => $p->paid_on->toDateString(), 'method' => $p->method, 'note' => $p->note])->values(),
        ];
    }

    public function assign(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate(['courier_id' => ['nullable', 'integer', Rule::exists('couriers', 'id')->where('company_id', $request->user()->company_id)]]);
        $this->fleet->assign($request->user(), $vehicle, isset($data['courier_id']) ? Courier::findOrFail($data['courier_id']) : null);

        return $this->show($request, $vehicle->fresh());
    }

    public function setPlan(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'maintenance_plan' => ['present', 'array'],
            'maintenance_plan.*.key' => ['nullable', 'string', 'max:30'],
            'maintenance_plan.*.label' => ['required', 'string', 'max:60'],
            'maintenance_plan.*.icon' => ['nullable', 'string', 'max:16'],
            'maintenance_plan.*.every_km' => ['nullable', 'integer', 'min:1'],
            'maintenance_plan.*.every_months' => ['nullable', 'integer', 'min:1'],
            'maintenance_plan.*.last_km' => ['nullable', 'integer', 'min:0'],
            'maintenance_plan.*.last_on' => ['nullable', 'date'],
        ]);
        $plan = collect($data['maintenance_plan'])->sortKeys()->values()->map(fn ($i) => [
            'key' => ($i['key'] ?? '') ?: Str::slug($i['label'], '_'),
            'label' => $i['label'], 'icon' => ($i['icon'] ?? '') ?: '🔧',
            'every_km' => $i['every_km'] ?? null, 'every_months' => $i['every_months'] ?? null,
            'last_km' => $i['last_km'] ?? $vehicle->odometer, 'last_on' => $i['last_on'] ?? today()->toDateString(),
        ])->all();
        $vehicle->forceFill(['maintenance_plan' => $plan, 'maintenance_reminded' => []])->save();

        return $this->show($request, $vehicle);
    }

    // ---- Papiers ----

    public function storeDocument(Request $request): JsonResponse
    {
        $company = $request->user()->company_id;
        $data = $request->validate([
            'vehicle_id' => ['nullable', 'required_without:courier_id', 'integer', Rule::exists('vehicles', 'id')->where('company_id', $company)],
            'courier_id' => ['nullable', 'required_without:vehicle_id', 'integer', Rule::exists('couriers', 'id')->where('company_id', $company)],
            ...$this->documentRules(),
        ]);
        $doc = VehicleDocument::create([...collect($data)->except(['file', 'file_original', 'file_meta'])->all(), 'company_id' => $company, ...$this->documentFile($request)]);

        return response()->json(['data' => $this->document($doc, $this->settings->for($request->user()->company))], 201);
    }

    public function updateDocument(Request $request, VehicleDocument $document): JsonResponse
    {
        $data = $request->validate($this->documentRules(true));
        $document->fill([...collect($data)->except(['file', 'file_original', 'file_meta'])->all(), ...$this->documentFile($request)]);
        // Nouvelle date de fin : les rappels repartent
        if ($document->isDirty('ends_on')) {
            $document->reminded = 0;
        }
        $document->save();

        return response()->json(['data' => $this->document($document, $this->settings->for($request->user()->company))]);
    }

    public function destroyDocument(VehicleDocument $document): JsonResponse
    {
        $document->delete();

        return response()->json(['data' => true]);
    }

    /**
     * Fichier du papier : photo passée au scanner (avec sa photo d'origine, selon le réglage) ou PDF.
     * Un nouveau fichier remplace aussi l'ancien original.
     */
    private function documentFile(Request $request): array
    {
        if (! $request->hasFile('file')) {
            return [];
        }
        $stored = app(DocumentPhoto::class)->store($request->user()->company, $request->file('file'), $request->file('file_original'), $request->input('file_meta'), 'vehicles/documents');

        return ['file_path' => $stored['path'], 'file_mime' => $stored['mime_type'], 'original_path' => $stored['original_path'], 'file_meta' => $stored['meta']];
    }

    /** Fichier du papier ; ?original=1 : la photo d'origine, avant le scanner */
    public function showDocumentFile(Request $request, VehicleDocument $document): StreamedResponse
    {
        $path = $request->boolean('original') ? $document->original_path : $document->file_path;
        abort_unless($path, 404);

        return Storage::disk('local')->response($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function documentRules(bool $update = false): array
    {
        return [
            'kind' => [$update ? 'sometimes' : 'required', Rule::in(array_keys(VehicleCatalog::DOCUMENTS))],
            'label' => ['nullable', 'string', 'max:80'],
            'reference' => ['nullable', 'string', 'max:80'],
            'issuer' => ['nullable', 'string', 'max:80'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
            'cost' => ['nullable', 'integer', 'min:0'],
            'file' => ['nullable', 'file', 'max:15360', 'mimes:jpg,jpeg,png,webp,pdf'],
            'file_original' => ['nullable', 'image', 'max:15360'],
            'file_meta' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** Papiers des livreurs (permis) */
    public function courierDocuments(Request $request): JsonResponse
    {
        $s = $this->settings->for($request->user()->company);

        return response()->json(['data' => VehicleDocument::whereNotNull('courier_id')->with('courier.user')->orderByDesc('ends_on')->get()
            ->map(fn (VehicleDocument $d) => [...$this->document($d, $s), 'courier' => $d->courier?->user?->name])->values()]);
    }

    // ---- Carnet ----

    public function storeLog(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(VehicleLog::KINDS))],
            'done_on' => ['nullable', 'date'],
            'odometer' => ['nullable', 'integer', 'min:0'],
            'label' => ['nullable', 'string', 'max:160'],
            'garage' => ['nullable', 'string', 'max:80'],
            'amount' => ['nullable', 'integer', 'min:0'],
            'liters' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'paid_by' => ['nullable', Rule::in(['company', 'courier'])],
            'courier_id' => ['nullable', 'integer', Rule::exists('couriers', 'id')->where('company_id', $request->user()->company_id)],
            'courier_share' => ['nullable', 'integer', 'min:0'],
            'charge_courier' => ['sometimes', 'boolean'],
            'plan_keys' => ['nullable', 'array'],
            'plan_keys.*' => ['string', 'max:30'],
            'immobilized_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'status' => ['nullable', Rule::in(array_keys(Vehicle::STATUSES))],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf'],
        ]);
        $this->logs->add($request->user(), $vehicle, collect($data)->except('photos')->all(), $request->file('photos', []));

        return $this->show($request, $vehicle->fresh());
    }

    public function destroyLog(Request $request, VehicleLog $log): JsonResponse
    {
        $vehicle = $log->vehicle;
        $this->logs->delete($request->user(), $log);

        return $this->show($request, $vehicle->fresh());
    }

    public function logPhoto(VehicleLog $log, int $index): StreamedResponse
    {
        $path = ($log->photos ?? [])[$index] ?? abort(404);

        return Storage::disk('local')->response($path);
    }

    public function checkPhoto(VehicleCheck $check, int $index): StreamedResponse
    {
        $path = ($check->photos ?? [])[$index] ?? abort(404);

        return Storage::disk('local')->response($path);
    }

    // ---- Location-vente ----

    public function storeLease(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'courier_id' => ['required', 'integer', Rule::exists('couriers', 'id')->where('company_id', $request->user()->company_id)],
            'kind' => ['required', Rule::in(['purchase', 'rental'])],
            'amount' => ['required', 'integer', 'min:1'],
            'period' => ['required', Rule::in(['day', 'week', 'month'])],
            'days_per_week' => ['nullable', 'integer', Rule::in([5, 6, 7])],
            'total_amount' => ['nullable', 'integer', 'min:1'],
            'starts_on' => ['nullable', 'date'],
            'payment' => ['required', Rule::in(['payroll', 'cash'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['courier_id' => 'livreur', 'amount' => 'montant', 'total_amount' => 'prix total']);
        if (! $this->settings->for($request->user()->company)['leases']) {
            throw new BusinessRuleException('La location-vente est désactivée (Réglages › Véhicules).');
        }
        $this->leases->create($request->user(), $vehicle, Courier::findOrFail($data['courier_id']), $data);

        return $this->show($request, $vehicle->fresh());
    }

    public function payLease(Request $request, VehicleLease $lease): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'paid_on' => ['nullable', 'date'],
            'method' => ['nullable', Rule::in(['cash', 'other'])],
            'note' => ['nullable', 'string', 'max:160'],
        ], [], ['amount' => 'montant']);
        $this->leases->pay($request->user(), $lease, $data['amount'], $data['method'] ?? 'cash', $data['paid_on'] ?? null, $data['note'] ?? null);

        return $this->show($request, $lease->vehicle->fresh());
    }

    public function cancelLease(Request $request, VehicleLease $lease): JsonResponse
    {
        $lease->forceFill(['status' => 'cancelled'])->save();
        $lease->vehicle->forceFill(['ownership' => 'company'])->save();

        return $this->show($request, $lease->vehicle->fresh());
    }

    // ---- Échéances et statistiques ----

    public function deadlines(Request $request): JsonResponse
    {
        $company = $request->user()->company_id;
        $s = $this->settings->for($request->user()->company);
        $alerts = collect($this->fleet->alerts($company));
        // Papiers à venir (90 jours), pas encore dans les alertes
        $upcoming = collect();
        foreach (Vehicle::where('status', '!=', 'sold')->get() as $v) {
            foreach ($this->fleet->latestDocuments($v) as $doc) {
                $state = $this->fleet->documentState($doc, $s);
                if ($state['state'] === 'ok' && $state['days'] <= 90) {
                    $upcoming->push(['kind' => 'document', 'icon' => VehicleCatalog::DOCUMENTS[$doc->kind]['icon'] ?? '📄', 'state' => 'ok', 'vehicle_id' => $v->id,
                        'title' => $v->plate, 'label' => VehicleCatalog::DOCUMENTS[$doc->kind]['label'] ?? $doc->label, 'days' => $state['days'], 'date' => $doc->ends_on?->toDateString()]);
                }
            }
        }

        return response()->json(['data' => $alerts->where('kind', '!=', 'status')->merge($upcoming)->values(), 'settings' => ['reminder_days' => $s['reminder_days'], 'block_expired' => $s['block_expired'], 'blocking_documents' => $s['blocking_documents']]]);
    }

    public function stats(Request $request, VehicleStats $stats): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        return response()->json(['data' => $stats->summary($request->user()->company_id, $data['from'], $data['to'])]);
    }
}
