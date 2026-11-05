<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Enums\VehicleType;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CourierResource;
use App\Models\Courier;
use App\Models\FieldEvent;
use App\Models\Vehicle;
use App\Services\Assignment\MadCouriers;
use App\Services\Couriers\DailyActivity;
use App\Services\Ratings\CourierScores;
use App\Services\Ratings\RatingSettings;
use App\Services\Reports\Statistics;
use App\Services\Vehicles\Fleet;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Page Livreurs : tableau paginé (note, chiffres de la période, filtres) et fiche complète d'un livreur.
 * La liste simple GET /couriers reste celle des listes déroulantes.
 */
class CourierDirectoryController extends Controller
{
    public const PERIODS = [7, 30, 90];

    private const SORTS = ['name', 'score', 'delivered', 'success_rate', 'delivery_minutes', 'missions', 'failed'];

    public function __construct(
        private readonly CourierScores $scores,
        private readonly RatingSettings $ratingSettings,
        private readonly Statistics $statistics,
        private readonly Fleet $fleet,
        private readonly MadCouriers $mad,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'zone_id' => ['nullable', 'integer'],
            'available' => ['nullable', 'boolean'],
            'vehicle_type' => ['nullable', Rule::enum(VehicleType::class)],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'rating' => ['nullable', Rule::in(['below', 'good', 'unrated', 'bad_week'])],
            'mad' => ['nullable', 'boolean'],
            'activity' => ['nullable', Rule::in(['ecommerce', 'distribution', 'mad'])],
            'days' => ['nullable', 'integer', Rule::in(self::PERIODS)],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:10,100'],
        ]);
        $company = $request->user()->company;
        $days = (int) ($data['days'] ?? 30);
        $status = $data['status'] ?? 'active';
        $perPage = (int) ($data['per_page'] ?? 25);
        $settings = $this->ratingSettings->for($company);

        $search = mb_strtolower(trim($data['search'] ?? ''));
        $digits = preg_replace('/\D+/', '', $search);
        $couriers = Courier::query()
            ->with(['user', 'zones'])
            ->withCount('activeAssignments')
            ->whereHas('user', fn ($q) => $q
                ->when($status === 'active', fn ($q) => $q->where('status', 'active'))
                ->when($status === 'inactive', fn ($q) => $q->where('status', '!=', 'active'))
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->when($digits !== '', fn ($q) => $q->orWhere('phone', 'like', "%{$digits}%")))))
            ->when(isset($data['available']), fn ($q) => $q->where('is_available', (bool) $data['available']))
            ->when(isset($data['vehicle_type']), fn ($q) => $q->where('vehicle_type', $data['vehicle_type']))
            ->when(isset($data['zone_id']), fn ($q) => $q->whereHas('zones', fn ($q) => $q->where('zones.id', $data['zone_id'])))
            ->get();

        $to = CarbonImmutable::now($company->timezone ?: config('app.timezone'));
        $from = $to->subDays($days - 1);
        $stats = collect($this->statistics->for($company)->forActivity($data['activity'] ?? null)->couriers($from, $to, false)['couriers'])->keyBy('id');
        $vehicles = Vehicle::query()->whereIn('courier_id', $couriers->pluck('id'))->get()->keyBy('courier_id');
        $warnings = $this->vehicleWarnings($company->id, $vehicles);
        $events = FieldEvent::currentByCourier();

        $rows = $couriers->map(function (Courier $c) use ($stats, $vehicles, $warnings, $events, $settings) {
            $s = $stats->get($c->id, []);
            $score = $this->scores->for($c);
            $vehicle = $vehicles->get($c->id);

            return [
                'id' => $c->id,
                'name' => $c->user?->name ?? "Livreur n° {$c->id}",
                'phone' => $c->user?->phone,
                'active' => $c->user?->status?->value === 'active',
                'is_available' => (bool) $c->is_available,
                'vehicle_type' => $c->vehicle_type?->value ?? $c->vehicle_type,
                'vehicle' => $vehicle ? ['id' => $vehicle->id, 'label' => trim($vehicle->plate.' '.($vehicle->brand ?? ''))] : null,
                'vehicle_plate' => $c->vehicle_plate,
                'vehicle_warnings' => $warnings[$c->id] ?? [],
                'zones' => $c->zones->reject(fn ($z) => (bool) $z->pivot?->is_backup)->pluck('name')->values(),
                'missions' => (int) $c->active_assignments_count,
                'last_location_at' => $c->last_location_at,
                'mad' => $this->mad->of($c),
                'field_event' => $events[$c->id] ?? null,
                'score' => $score['score'],
                'bad_last_7_days' => (int) ($score['bad_last_7_days'] ?? 0),
                'below_threshold' => ($score['score'] !== null && $score['score'] < (float) $settings['alert_below'])
                    || ((int) $settings['alert_bad_count'] > 0 && ($score['bad_last_7_days'] ?? 0) >= (int) $settings['alert_bad_count']),
                'delivered' => (int) ($s['delivered'] ?? 0),
                'pickups' => (int) ($s['pickups'] ?? 0),
                'failed' => (int) ($s['failed'] ?? 0),
                'success_rate' => $s['success_rate'] ?? null,
                'delivery_minutes' => $s['delivery_minutes'] ?? null,
                'days_worked' => (int) ($s['days_worked'] ?? 0),
                'per_day' => $s['per_day'] ?? null,
            ];
        });

        // Résumé avant les filtres note et MAD : le nombre de livreurs concernés par chaque filtre
        $scored = $rows->whereNotNull('score');
        $summary = [
            'couriers' => $rows->count(),
            'available' => $rows->where('is_available', true)->count(),
            'mad' => $rows->whereNotNull('mad')->count(),
            'average_score' => $scored->isEmpty() ? null : round($scored->avg('score'), 1),
            'below' => $rows->where('below_threshold', true)->count(),
            'field_events' => $rows->whereNotNull('field_event')->count(),
            'vehicle_alerts' => $rows->filter(fn ($r) => $r['vehicle_warnings'] !== [])->count(),
            'delivered' => (int) $rows->sum('delivered'),
        ];

        $rows = match ($data['rating'] ?? null) {
            'below' => $rows->where('below_threshold', true),
            'good' => $rows->filter(fn ($r) => $r['score'] !== null && $r['score'] >= 4),
            'unrated' => $rows->whereNull('score'),
            'bad_week' => $rows->where('bad_last_7_days', '>', 0),
            default => $rows,
        };
        if (isset($data['mad'])) {
            $rows = $rows->filter(fn ($r) => ($r['mad'] !== null) === (bool) $data['mad']);
        }

        $sort = $data['sort'] ?? 'name';
        $desc = ($data['dir'] ?? ($sort === 'name' ? 'asc' : 'desc')) === 'desc';
        $rows = $rows->sort(function (array $a, array $b) use ($sort, $desc) {
            if ($sort === 'name') {
                return ($desc ? -1 : 1) * strcasecmp($a['name'], $b['name']);
            }
            // Sans valeur (pas de note, pas de livraison) : toujours en bas
            if ($a[$sort] === null || $b[$sort] === null) {
                return ($a[$sort] === null) <=> ($b[$sort] === null) ?: strcasecmp($a['name'], $b['name']);
            }

            return ($desc ? -1 : 1) * ($a[$sort] <=> $b[$sort]) ?: strcasecmp($a['name'], $b['name']);
        })->values();

        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min((int) ($data['page'] ?? 1), $lastPage);

        return response()->json([
            'data' => $rows->slice(($page - 1) * $perPage, $perPage)->values(),
            'meta' => [
                'total' => $total, 'current_page' => $page, 'per_page' => $perPage, 'last_page' => $lastPage, 'days' => $days,
                'from' => $total ? ($page - 1) * $perPage + 1 : 0, 'to' => min($total, $page * $perPage),
            ],
            'summary' => $summary,
            'rating_settings' => collect($settings)->only(['window_days', 'alert_below', 'alert_bad_count']),
        ]);
    }

    /**
     * Fiche d'un livreur : profil, chiffres de la période, jour par jour, aujourd'hui.
     */
    public function show(Request $request, Courier $courier, DailyActivity $activity): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', Rule::in(self::PERIODS)]]);
        $company = $request->user()->company;
        $days = (int) ($data['days'] ?? 30);
        $withAmounts = $request->user()->can(Permission::FinanceView->value);

        $to = CarbonImmutable::now($company->timezone ?: config('app.timezone'));
        $from = $to->subDays($days - 1);
        $stats = $this->statistics->for($company);
        $figures = collect($stats->couriers($from, $to, $withAmounts)['couriers'])->firstWhere('id', $courier->id);
        $today = collect($activity->day($company)['data'] ?? [])->firstWhere('id', $courier->id);
        $score = $this->scores->for($courier);

        $courier->load(['user', 'zones', 'payPlan'])->loadCount('activeAssignments');
        $vehicle = $this->fleet->currentFor($courier);

        return response()->json(['data' => [
            'courier' => CourierResource::make($courier)->resolve($request),
            'created_at' => $courier->created_at,
            'last_login_at' => $courier->user?->last_login_at,
            'active' => $courier->user?->status?->value === 'active',
            'days' => $days,
            'score' => $score['score'],
            'figures' => $figures ?? [
                'delivered' => 0, 'pickups' => 0, 'failed' => 0, 'returned' => 0, 'success_rate' => null, 'delivery_minutes' => null,
                'days_worked' => 0, 'per_day' => null, 'collected' => $withAmounts ? 0 : null, 'cash_gap' => $withAmounts ? 0 : null, 'earnings' => $withAmounts ? 0 : null,
            ],
            'series' => $stats->courierDays($courier->id, $from, $to),
            'today' => $today ? collect($today)->only(['state', 'deliveries', 'pickups', 'failed', 'remaining', 'progress', 'estimated_end', 'parcels_in_hand', 'cash_in_hand', 'flags'])->all() : null,
            'mad' => $this->mad->of($courier),
            'field_event' => FieldEvent::currentByCourier()[$courier->id] ?? null,
            'vehicle' => $vehicle ? ['id' => $vehicle->id, 'plate' => $vehicle->plate, 'label' => trim($vehicle->plate.' '.($vehicle->brand ?? '').' '.($vehicle->model ?? '')), 'status' => $vehicle->status] : null,
            'vehicle_warnings' => $this->vehicleWarnings($company->id, collect($vehicle ? [$courier->id => $vehicle] : []))[$courier->id] ?? [],
            'with_amounts' => $withAmounts,
        ]]);
    }

    /**
     * Alertes du parc (papiers, entretien, véhicule arrêté, permis) rangées par livreur.
     *
     * @param  Collection<int, Vehicle>  $vehiclesByCourier
     * @return array<int, list<array{level: string, text: string}>>
     */
    private function vehicleWarnings(int $companyId, Collection $vehiclesByCourier): array
    {
        $courierOfVehicle = $vehiclesByCourier->mapWithKeys(fn (Vehicle $v, $courierId) => [$v->id => $courierId]);
        $out = [];
        foreach ($this->fleet->alerts($companyId) as $a) {
            $courierId = $a['courier_id'] ?? (isset($a['vehicle_id']) ? $courierOfVehicle->get($a['vehicle_id']) : null);
            if (! $courierId || $a['kind'] === 'lease') {
                continue;
            }
            $out[$courierId][] = [
                'level' => $a['state'] === 'expired' ? 'alert' : 'warning',
                'text' => trim(($a['icon'] ?? '').' '.$a['label'].($a['state'] === 'expired' ? ' : à régulariser' : ' : bientôt')),
            ];
        }

        return $out;
    }
}
