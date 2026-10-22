<?php

namespace App\Services\Couriers;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;
use App\Models\Zone;
use App\Notifications\CourierAvailableAlert;
use App\Services\Orders\OrderDispatcher;
use App\Services\Orders\OrderJournal;
use App\Services\Orders\PickupGroups;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Livreurs qui ont fini (ou presque) leur tournée, et ce qu'il y a à faire près d'eux.
 *  - charge : missions encore en cours (ramassage, livraison, retour) ;
 *  - « zone d'arrivée » : zone du dernier arrêt de la journée (ramassage chez le marchand,
 *    livraison chez le client), avec la dernière position GPS si elle est récente ;
 *  - ramassages possibles près de lui : colis validés que personne ne va chercher, dans sa
 *    zone d'arrivée, dans ses zones habituelles ou à moins de RADIUS_KM de sa position.
 *    Un ramassage ne change pas la date de livraison (souvent demain, quand la journée est fermée).
 * Le dispatch est alerté quand un livreur devient libre ou bientôt libre et qu'il y a des
 * ramassages près de lui ; il peut alors lui confier une « mission ramassage » d'un coup.
 */
class CourierAvailability
{
    /** Bientôt libre : au plus ce nombre de missions en cours */
    public const SOON = 2;

    /** Ramassages « proches » : à vol d'oiseau depuis la dernière position */
    public const RADIUS_KM = 3.0;

    /** Position GPS considérée comme actuelle */
    private const FRESH_POSITION_MINUTES = 30;

    public function __construct(
        private readonly OrderDispatcher $dispatcher,
        private readonly OrderJournal $journal,
        private readonly PickupCustody $custody,
    ) {}

    /**
     * Tableau des livreurs actifs : libres d'abord, puis bientôt libres, occupés, hors service.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function board(Company $company): Collection
    {
        $zones = Zone::withoutGlobalScopes()->where('company_id', $company->id)->get(['id', 'name', 'parent_id'])->keyBy('id');
        $candidates = $this->pickupCandidates($company);
        [$since, $today] = $this->day($company);

        return Courier::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->with(['user', 'zones:id,name,parent_id', 'activeAssignments:id,courier_id,order_id,type'])
            ->get()
            ->map(fn (Courier $courier) => $this->row($courier, $zones, $candidates, $since, $today))
            ->sortBy(fn (array $r) => [array_search($r['state'], ['free', 'soon', 'busy', 'off'], true), -$r['nearby']['count'], $r['name']])
            ->values();
    }

    /**
     * Ligne d'un livreur, avec le détail des ramassages proches.
     *
     * @return array<string, mixed>
     */
    public function forCourier(Courier $courier): array
    {
        $company = Company::findOrFail($courier->company_id);
        $zones = Zone::withoutGlobalScopes()->where('company_id', $company->id)->get(['id', 'name', 'parent_id'])->keyBy('id');
        $courier->loadMissing(['user', 'zones:id,name,parent_id', 'activeAssignments:id,courier_id,order_id,type']);

        [$since, $today] = $this->day($company);

        return $this->row($courier, $zones, $this->pickupCandidates($company), $since, $today, withStops: true);
    }

    /**
     * @param  Collection<int, Zone>  $zones
     * @param  Collection<int, Order>  $candidates
     * @return array<string, mixed>
     */
    private function row(Courier $courier, Collection $zones, Collection $candidates, CarbonImmutable $since, string $today, bool $withStops = false): array
    {
        $active = $courier->activeAssignments;
        $remaining = $active->count();

        $done = OrderAssignment::query()
            ->where('courier_id', $courier->id)
            ->whereIn('status', [AssignmentStatus::Completed->value, AssignmentStatus::Failed->value])
            ->where('completed_at', '>=', $since)
            ->with('order:id,pickup_zone_id,delivery_zone_id')
            ->latest('completed_at')
            ->get(['id', 'order_id', 'type', 'status', 'completed_at']);
        $last = $done->first();

        // Zone du dernier arrêt : chez le marchand (ramassage, retour) ou chez le client (livraison)
        $arrivalZoneId = $last ? ($last->type === AssignmentType::Delivery ? $last->order?->delivery_zone_id : $last->order?->pickup_zone_id) : null;

        $fresh = $courier->current_lat !== null && $courier->last_location_at?->gt(now()->subMinutes(self::FRESH_POSITION_MINUTES));
        $position = $fresh ? ['lat' => $courier->current_lat, 'lng' => $courier->current_lng, 'at' => $courier->last_location_at->toIso8601String()] : null;

        $state = match (true) {
            ! $courier->is_available => 'off',
            $remaining === 0 => 'free',
            $remaining <= self::SOON => 'soon',
            default => 'busy',
        };

        $stops = $state === 'off' ? collect() : $this->nearbyStops($courier, $candidates, $zones, $arrivalZoneId, $position, $today);

        return [
            'id' => $courier->id,
            'name' => $courier->user->name,
            'phone' => $courier->user->phone,
            'is_available' => $courier->is_available,
            'zones' => $courier->zones->pluck('name')->values(),
            'state' => $state,
            'remaining' => [
                'total' => $remaining,
                'pickup' => $active->where('type', AssignmentType::Pickup)->count(),
                'delivery' => $active->where('type', AssignmentType::Delivery)->count(),
                'return' => $active->where('type', AssignmentType::Return)->count(),
            ],
            'done_today' => $done->count(),
            'last_stop_at' => $last?->completed_at?->toIso8601String(),
            'arrival_zone' => $arrivalZoneId ? ['id' => $arrivalZoneId, 'name' => $zones->get($arrivalZoneId)?->name] : null,
            'position' => $position,
            'nearby' => [
                'count' => $stops->sum('count'),
                'stops' => $stops->count(),
                'for_tomorrow' => $stops->sum('for_tomorrow'),
            ],
            ...($withStops ? ['stops' => $stops->values()->all()] : []),
        ];
    }

    /**
     * Colis validés que personne ne va chercher chez le marchand (même règle que les ramassages par client).
     *
     * @return Collection<int, Order>
     */
    private function pickupCandidates(Company $company): Collection
    {
        return Order::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', OrderStatus::Confirmed->value)
            ->whereNull('pickup_hub_id')
            ->whereNotNull('merchant_id')
            ->whereDoesntHave('assignments', fn ($q) => $q->active()->where('type', AssignmentType::Pickup->value))
            ->with(['merchant:id,business_name,phone,pickup_lat,pickup_lng,pickup_address', 'deliveryZone:id,name'])
            ->orderBy('confirmed_at')
            ->get();
    }

    /**
     * Arrêts de ramassage (un par marchand et adresse) près du livreur, les plus proches d'abord.
     *
     * @param  Collection<int, Order>  $candidates
     * @param  Collection<int, Zone>  $zones
     * @param  array{lat: float, lng: float}|null  $position
     * @return Collection<int, array<string, mixed>>
     */
    private function nearbyStops(Courier $courier, Collection $candidates, Collection $zones, ?int $arrivalZoneId, ?array $position, string $today): Collection
    {
        // Une zone et ses quartiers forment une même famille (Cocody, Cocody › Angré)
        $family = fn (?int $id) => $id === null ? null : ($zones->get($id)?->parent_id ?? $id);
        $arrival = $family($arrivalZoneId);
        $usual = $courier->zones->map(fn (Zone $z) => $family($z->id))->unique()->all();

        return $candidates
            ->groupBy(fn (Order $o) => PickupGroups::key($o))
            ->map(function (Collection $group) use ($family, $arrival, $usual, $position, $today, $zones) {
                /** @var Order $first */
                $first = $group->first();
                $lat = $first->pickup_lat ?? $first->merchant?->pickup_lat;
                $lng = $first->pickup_lng ?? $first->merchant?->pickup_lng;
                $km = $position && $lat !== null && $lng !== null ? round(self::km($position['lat'], $position['lng'], (float) $lat, (float) $lng), 1) : null;
                $zone = $family($first->pickup_zone_id);

                $reason = match (true) {
                    $km !== null && $km <= self::RADIUS_KM => 'near',
                    $arrival !== null && $zone === $arrival => 'arrival_zone',
                    in_array($zone, $usual, true) => 'usual_zone',
                    default => null,
                };
                if ($reason === null) {
                    return null;
                }

                return [
                    'key' => PickupGroups::key($first),
                    'reason' => $reason,
                    'km' => $km,
                    'merchant' => $first->merchant?->business_name,
                    'phone' => $first->pickup_phone ?? $first->merchant?->phone,
                    'address' => $first->pickup_address ?? $first->merchant?->pickup_address,
                    'zone_name' => $zones->get($first->pickup_zone_id)?->name,
                    'count' => $group->count(),
                    'for_tomorrow' => $group->filter(fn (Order $o) => $o->delivery_scheduled_date?->toDateString() > $today)->count(),
                    'waiting_since' => $group->min('confirmed_at'),
                    'orders' => $group->map(fn (Order $o) => [
                        'id' => $o->id,
                        'tracking_code' => $o->tracking_code,
                        'recipient_name' => $o->recipient_name,
                        'delivery_zone_name' => $o->deliveryZone?->name,
                        'delivery_date' => $o->delivery_scheduled_date?->toDateString(),
                    ])->values()->all(),
                ];
            })
            ->filter()
            // Les plus proches d'abord ; sans position : sa zone d'arrivée, puis ses zones habituelles
            ->sortBy(fn (array $s) => [$s['km'] ?? 999, ['near' => 0, 'arrival_zone' => 1, 'usual_zone' => 2][$s['reason']], $s['waiting_since']])
            ->values();
    }

    /**
     * Mission ramassage : confie d'un coup au livreur les colis choisis. Ceux qu'un autre
     * livreur va déjà chercher sont laissés de côté (pas de réassignation par erreur).
     * $custody (keep, depot) : choix du dispatch quand l'entreprise le laisse ouvert.
     *
     * @param  list<int>  $orderIds
     * @return array{assigned: list<int>, errors: array<int, string>, warnings: array<int, string>, custody: string, fallback: bool}
     */
    public function assignPickupMission(User $actor, Courier $courier, array $orderIds, ?string $custody = null): array
    {
        $rule = $this->custody->resolve(Company::findOrFail($courier->company_id), $custody);

        $taken = Order::whereIn('id', $orderIds)
            ->whereHas('assignments', fn ($q) => $q->active()->where('type', AssignmentType::Pickup->value))
            ->pluck('id')->all();

        $result = $this->dispatcher->assignMany($actor, array_values(array_diff($orderIds, $taken)), AssignmentType::Pickup, $courier);
        foreach ($taken as $id) {
            $result['errors'][$id] = 'Un livreur va déjà chercher ce colis.';
        }

        if ($result['assigned']) {
            $courier->loadMissing('user');
            Order::whereIn('id', $result['assigned'])->update(['custody_plan' => $rule['plan']]);
            $then = $rule['plan'] === Company::CUSTODY_KEEP
                ? 'Il garde le colis jusqu\'à sa livraison, qui lui sera confiée le jour prévu'.($rule['fallback'] ? ' (dépôt fermé à cette heure)' : '').'.'
                : 'Il rapporte le colis au dépôt.';
            foreach (Order::whereIn('id', $result['assigned'])->get() as $order) {
                $this->journal->record($order, $actor, OrderEventType::Note, [
                    'note' => "Mission ramassage confiée à {$courier->user->name}, libre près de ce client. {$then} La date de livraison ne change pas.",
                    'visible_to_merchant' => false,
                ]);
            }
        }

        return [...$result, 'custody' => $rule['plan'], 'fallback' => $rule['fallback']];
    }

    /**
     * Alerte le dispatch quand un livreur qui a déjà travaillé aujourd'hui devient libre ou
     * bientôt libre avec des ramassages près de lui. Une alerte par palier et par jour.
     *
     * @return int nombre d'alertes envoyées
     */
    public function alert(): int
    {
        $sent = 0;

        Company::query()->where('status', 'active')->where('courier_available_alert', true)->each(function (Company $company) use (&$sent) {
            $today = CarbonImmutable::now($company->timezone ?: config('app.timezone'))->toDateString();

            // Calcul seulement s'il y a des livreurs libres ou presque (économise la requête des candidats)
            $couriers = Courier::withoutGlobalScopes()->where('company_id', $company->id)->where('is_available', true)
                ->whereHas('user', fn ($q) => $q->where('status', 'active'))
                ->withCount('activeAssignments')
                ->get()
                ->filter(fn (Courier $c) => $c->active_assignments_count <= self::SOON)
                ->filter(fn (Courier $c) => $this->shouldAlert($c, $c->active_assignments_count === 0 ? 'free' : 'soon', $today));
            if ($couriers->isEmpty()) {
                return;
            }

            $board = $this->board($company)->keyBy('id');
            $users = null;

            foreach ($couriers as $courier) {
                $row = $board->get($courier->id);
                if (! $row || ! in_array($row['state'], ['free', 'soon'], true) || $row['done_today'] === 0 || $row['nearby']['count'] === 0) {
                    continue;
                }

                $users ??= User::forCompany($company->id)->where('status', 'active')->role([Role::Admin->value, Role::Dispatcher->value])->get();
                if ($users->isEmpty()) {
                    return;
                }

                Notification::send($users, new CourierAvailableAlert($courier, $row));
                $courier->forceFill(['availability_alerted_on' => $today, 'availability_alert_level' => $row['state']])->save();
                $sent++;
            }
        });

        return $sent;
    }

    /** Déjà alerté aujourd'hui à ce palier (ou au palier « libre », plus fort) ? */
    private function shouldAlert(Courier $courier, string $level, string $today): bool
    {
        if ($courier->availability_alerted_on?->toDateString() !== $today) {
            return true;
        }

        return $level === 'free' && $courier->availability_alert_level !== 'free';
    }

    /**
     * Début de la journée locale de l'entreprise (au fuseau de l'application) et sa date.
     *
     * @return array{0: CarbonImmutable, 1: string}
     */
    private function day(Company $company): array
    {
        $local = CarbonImmutable::now($company->timezone ?: config('app.timezone'))->startOfDay();

        return [$local->setTimezone(config('app.timezone')), $local->toDateString()];
    }

    /**
     * Distance à vol d'oiseau (formule de haversine), en kilomètres.
     */
    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
