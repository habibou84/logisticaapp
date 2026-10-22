<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Enums\VehicleType;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CourierResource;
use App\Models\Company;
use App\Models\Courier;
use App\Services\Couriers\CourierTracker;
use App\Services\Orders\AwaitingCourier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Profils livreurs. Les comptes sont créés via /users (rôle « courier ») :
 * le profil est créé automatiquement.
 */
class CourierController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'zone_id' => ['nullable', 'integer'],
            'available' => ['nullable', 'boolean'],
        ]);

        $couriers = Courier::query()
            ->with(['user', 'zones', 'payPlan'])
            ->withCount('activeAssignments')
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->when($request->has('available'), fn ($q) => $q->where('is_available', $request->boolean('available')))
            ->when($request->filled('zone_id'), fn ($q) => $q->whereHas('zones', fn ($q) => $q->where('zones.id', $request->integer('zone_id'))))
            ->get()
            ->sortBy(fn (Courier $c) => [! $c->is_available, $c->active_assignments_count, $c->user->name])
            ->values();

        return CourierResource::collection($couriers);
    }

    /**
     * Carte des livreurs : dernière position connue et missions en cours de chaque livreur actif.
     */
    public function map(Request $request, AwaitingCourier $awaiting): JsonResponse
    {
        $couriers = Courier::query()
            ->with(['user', 'activeAssignments' => fn ($q) => $q->with(['order.pickupZone', 'order.deliveryZone'])->orderBy('id')])
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->get()
            ->map(fn (Courier $c) => [
                'id' => $c->id,
                'name' => $c->user->name,
                'phone' => $c->user->phone,
                'vehicle_type' => $c->vehicle_type,
                'is_available' => $c->is_available,
                'lat' => $c->current_lat,
                'lng' => $c->current_lng,
                'last_location_at' => $c->last_location_at,
                'missions' => $c->activeAssignments->map(fn ($a) => [
                    'order_id' => $a->order_id,
                    'tracking_code' => $a->order?->tracking_code,
                    'type' => $a->type->value,
                    'type_label' => $a->type->label(),
                    'status' => $a->status->value,
                    'zone_name' => $a->type->value === 'pickup' ? $a->order?->pickupZone?->name : $a->order?->deliveryZone?->name,
                ])->values(),
            ])
            ->sortBy(fn ($c) => [$c['lat'] === null, ! $c['is_available'], $c['name']])
            ->values();

        return response()->json([
            'data' => $couriers,
            'pickups' => $this->waitingPickups($request->user()->company, $awaiting, $couriers),
        ]);
    }

    /**
     * Ramassages sans livreur, placés chez le marchand (position de la course, sinon celle du
     * marchand), avec les livreurs en service les plus proches à vol d'oiseau.
     *
     * @param  Collection<int, array<string, mixed>>  $couriers
     * @return list<array<string, mixed>>
     */
    private function waitingPickups(Company $company, AwaitingCourier $awaiting, Collection $couriers): array
    {
        $candidates = $couriers->filter(fn ($c) => $c['is_available'] && $c['lat'] !== null);

        return $awaiting->waiting($company)->where('stage', AwaitingCourier::PICKUP)
            ->sortByDesc('minutes')
            ->map(function (array $w) use ($candidates) {
                $order = $w['order']->loadMissing(['merchant', 'pickupZone']);
                $lat = $order->pickup_lat ?? $order->merchant?->pickup_lat;
                $lng = $order->pickup_lng ?? $order->merchant?->pickup_lng;

                return [
                    'order_id' => $order->id,
                    'tracking_code' => $order->tracking_code,
                    'merchant' => $order->merchant?->business_name,
                    'address' => $order->pickup_address ?? $order->merchant?->pickup_address,
                    'zone_name' => $order->pickupZone?->name,
                    'lat' => $lat !== null ? (float) $lat : null,
                    'lng' => $lng !== null ? (float) $lng : null,
                    'minutes' => $w['minutes'],
                    'late' => $w['late'],
                    'after_cutoff' => $w['after_cutoff'],
                    'nearest' => $lat === null ? [] : $candidates
                        ->map(fn ($c) => [
                            'id' => $c['id'], 'name' => $c['name'], 'missions' => count($c['missions']),
                            'km' => round(self::km((float) $lat, (float) $lng, $c['lat'], $c['lng']), 1),
                            'last_location_at' => $c['last_location_at'],
                        ])
                        ->sortBy('km')->take(3)->values()->all(),
                ];
            })->values()->all();
    }

    /**
     * Distance à vol d'oiseau (formule de haversine), en kilomètres.
     */
    private static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Trajet d'une journée : tracé, étapes des courses (ramassé, livré, échec…) et résumé.
     */
    public function track(Request $request, Courier $courier, CourierTracker $tracker): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']]);

        $courier->loadMissing('user');

        return response()->json(['data' => [
            'courier' => ['id' => $courier->id, 'name' => $courier->user?->name, 'phone' => $courier->user?->phone],
            ...$tracker->day($courier, $request->filled('date') ? Carbon::parse($request->string('date')) : today()),
        ]]);
    }

    public function show(Courier $courier): CourierResource
    {
        return CourierResource::make($courier->load(['user', 'zones', 'payPlan'])->loadCount('activeAssignments'));
    }

    public function update(Request $request, Courier $courier): CourierResource
    {
        $data = $request->validate([
            'vehicle_type' => ['sometimes', Rule::enum(VehicleType::class)],
            'vehicle_plate' => ['sometimes', 'nullable', 'string', 'max:30'],
            // Plan partagé de l'entreprise, ou plan personnel de ce livreur ; null = plan par défaut
            'pay_plan_id' => ['sometimes', 'nullable', 'integer', Rule::exists('pay_plans', 'id')->where('company_id', $courier->company_id)
                ->where(fn ($q) => $q->whereNull('courier_id')->orWhere('courier_id', $courier->id))],
            'is_available' => ['sometimes', 'boolean'],
            // Exception à la règle d'acceptation des missions de l'entreprise (null : celle de l'entreprise)
            'mission_acceptance' => ['sometimes', 'nullable', Rule::in(Company::ACCEPT_MODES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'zone_ids' => ['sometimes', 'array'],
            'zone_ids.*' => ['integer', Rule::exists('zones', 'id')->where('company_id', $courier->company_id)],
            // Zones de secours : servies par la livraison automatique quand personne de la zone principale n'est libre
            'backup_zone_ids' => ['sometimes', 'array'],
            'backup_zone_ids.*' => ['integer', Rule::exists('zones', 'id')->where('company_id', $courier->company_id)],
            'max_parcels' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:200'],
            'max_cash' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'shift_ends_at' => ['sometimes', 'nullable', 'date_format:H:i'],
        ], [], [
            'backup_zone_ids' => 'zones de secours', 'max_parcels' => 'colis au maximum', 'max_cash' => 'argent en main au maximum',
            'shift_ends_at' => 'fin de service',
        ]);

        // La rémunération relève des réglages de l'entreprise
        abort_if(array_key_exists('pay_plan_id', $data) && ! $request->user()->can(Permission::SettingsManage->value), 403, 'Seul un administrateur peut changer le plan de paie.');
        abort_if(array_key_exists('mission_acceptance', $data) && $data['mission_acceptance'] !== $courier->mission_acceptance
            && ! $request->user()->can(Permission::SettingsManage->value), 403, 'Seul un administrateur peut changer la règle d\'acceptation des missions.');

        $courier->update(collect($data)->except(['zone_ids', 'backup_zone_ids'])->all());

        // Une zone est principale ou de secours, pas les deux
        if (array_key_exists('zone_ids', $data) || array_key_exists('backup_zone_ids', $data)) {
            $main = $data['zone_ids'] ?? $courier->zones()->wherePivot('is_backup', false)->pluck('zones.id')->all();
            $backup = array_diff($data['backup_zone_ids'] ?? $courier->zones()->wherePivot('is_backup', true)->pluck('zones.id')->all(), $main);
            $courier->zones()->sync(
                collect($main)->mapWithKeys(fn ($id) => [$id => ['is_backup' => false]])->all()
                + collect($backup)->mapWithKeys(fn ($id) => [$id => ['is_backup' => true]])->all()
            );
        }

        return CourierResource::make($courier->load(['user', 'zones', 'payPlan'])->loadCount('activeAssignments'));
    }
}
