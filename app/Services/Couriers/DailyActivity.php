<?php

namespace App\Services\Couriers;

use App\Enums\Activity;
use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Enums\PayBonusMetric;
use App\Enums\ShipmentType;
use App\Models\BillingPlan;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\OrderAttachment;
use App\Models\OrderEvent;
use App\Services\Assignment\MadCouriers;
use App\Services\Finance\ClientSpace;
use App\Services\Finance\CourierCash;
use App\Services\Finance\CourierPay;
use App\Services\Finance\PayBonuses;
use App\Services\Orders\ActivityOptions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Activité des livreurs, suivi d'un jour (repris de Jibiat, adapté à la distribution et à la MAD) : pour chaque
 * livreur, ses livraisons et ramassages faits sur prévus, ses échecs, son rythme et son taux de réussite comparés à
 * ses 30 derniers jours, l'heure à laquelle il devrait finir, sa prime, ses colis et son argent en main, et ce qui
 * demande l'attention.
 * - Un colis compte pour son jour de livraison (date prévue, sinon jour de création). Aujourd'hui, les missions
 *   encore à faire des colis prévus avant sont comptées à part (« en retard d'avant »), hors avancement.
 * - Distribution : plis et chèques comptés un à un, accusé de réception manquant, délai interne dépassé.
 * - MAD : le livreur réservé à un client est « en MAD chez … » ; il n'entre ni dans le rythme ni dans la fin estimée.
 */
class DailyActivity
{
    /** Sans position depuis plus longtemps, avec des missions en cours : signalé */
    public const SILENT_MINUTES = 20;

    /** Habitude : jours précédents pris en compte */
    public const HABIT_DAYS = 30;

    /** Argent gardé plus longtemps (heures) sans plafond réglé : signalé */
    public const CASH_HOURS = 24;

    public function __construct(
        private readonly CourierCash $cash,
        private readonly MadCouriers $mad,
        private readonly ActivityOptions $options,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function day(Company $company, ?string $date = null, ?string $activity = null): array
    {
        [$localDay, $isToday, $isFuture] = $this->resolveDay($company, $date);
        $day = $localDay->toDateString();
        $since = $localDay->setTimezone(config('app.timezone'));
        $until = $localDay->addDay()->setTimezone(config('app.timezone'));

        $couriers = Courier::withoutGlobalScopes()->where('company_id', $company->id)
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->with(['user:id,name,phone', 'zones:id,name'])
            ->get();
        $ids = $couriers->modelKeys();

        $assignments = $this->ofDay(OrderAssignment::query()->whereIn('courier_id', $ids), $since, $until, $day, $isToday, $activity)
            ->with(['order' => fn ($q) => $q->withoutGlobalScopes()->select(['id', 'company_id', 'merchant_id', 'billing_plan_id', 'activity', 'shipment_type',
                'shipment_quantity', 'delivery_scheduled_date', 'created_at', 'internal_due_at', 'status'])])
            ->get(['id', 'courier_id', 'order_id', 'type', 'status', 'completed_at', 'unreachable_at', 'late_alerted_at'])
            ->groupBy('courier_id');

        $habits = $this->habits($ids, $since);
        $holdLimit = $company->parcel_hold_alert_hours > 0 ? now()->subHours($company->parcel_hold_alert_hours) : null;
        $missing = $this->missingReceipts($assignments->flatten(1));
        // Livreurs mis à disposition d'un client ce jour-là
        $madToday = $isFuture || $isToday ? $this->mad->reservations($company->id, $isToday ? null : $localDay->setTime(12, 0)) : [];

        if ($isToday) {
            // Colis en main : comptés par la base, sans charger les colis
            $held = Order::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('held_by_courier_id', $ids)
                ->groupBy('held_by_courier_id')
                ->selectRaw('held_by_courier_id, COUNT(*) AS n, SUM(CASE WHEN held_since <= ? THEN 1 ELSE 0 END) AS overdue', [($holdLimit ?? now()->subYears(100))->toDateTimeString()])
                ->get()->keyBy('held_by_courier_id');
            $reports = OrderEvent::query()->fieldReports()->whereDoesntHave('review')
                ->whereHas('order', fn ($q) => $q->withoutGlobalScopes()->where('company_id', $company->id))
                ->where('created_at', '>=', now()->subDays(30))
                ->whereIn('actor_id', $couriers->pluck('user_id')->values())
                ->selectRaw('actor_id, COUNT(*) AS n')->groupBy('actor_id')->pluck('n', 'actor_id');
            $balances = $this->cash->balances();
        } else {
            $held = collect();
            $reports = collect();
            $balances = [];
        }
        // Fermeture du dépôt, à l'heure du serveur
        $closesAt = $company->depot_closes_at ? $localDay->setTimeFromTimeString((string) $company->depot_closes_at)->setTimezone(config('app.timezone')) : null;

        $rows = $couriers->map(function (Courier $courier) use ($assignments, $held, $holdLimit, $reports, $balances, $habits, $isToday, $isFuture, $closesAt, $day, $missing, $madToday) {
            /** @var Collection<int, OrderAssignment> $all */
            $all = $assignments->get($courier->id, collect());
            [$before, $mine] = $all->partition(fn (OrderAssignment $a) => self::fromBefore($a, $day));
            $mad = $madToday[$courier->id] ?? null;
            // En MAD : ses courses pour le client se font pendant la mise à disposition (hors rythme et fin estimée)
            [$madMissions, $own] = $mine->partition(fn (OrderAssignment $a) => $mad !== null && $a->order?->merchant_id === $mad['merchant_id']);

            $count = fn (Collection $list, AssignmentType $type, ?array $statuses = null) => $list
                ->filter(fn (OrderAssignment $a) => $a->type === $type && ($statuses === null || in_array($a->status, $statuses, true)))->count();
            // Plis et chèques comptés un à un (distribution)
            $units = fn (Collection $list) => (int) $list->sum(fn (OrderAssignment $a) => self::units($a->order));

            $active = $own->filter(fn (OrderAssignment $a) => in_array($a->status, AssignmentStatus::active(), true));
            $deliveryList = $mine->filter(fn (OrderAssignment $a) => $a->type === AssignmentType::Delivery);
            $deliveries = [
                'done' => $count($mine, AssignmentType::Delivery, [AssignmentStatus::Completed]),
                'total' => $count($mine, AssignmentType::Delivery),
                // Plis : seulement si le livreur en a (distribution)
                'units_done' => $units($deliveryList->where('status', AssignmentStatus::Completed)),
                'units_total' => $units($deliveryList),
            ];
            $pickups = ['done' => $count($mine, AssignmentType::Pickup, [AssignmentStatus::Completed]), 'total' => $count($mine, AssignmentType::Pickup)];
            $failedDeliveries = $count($mine, AssignmentType::Delivery, [AssignmentStatus::Failed]);
            $failed = $mine->where('status', AssignmentStatus::Failed)->count();
            $attempted = $deliveries['done'] + $failedDeliveries;

            // Rythme du jour : minutes entre deux missions faites (de la première à la dernière), hors MAD
            $finished = $own->whereIn('status', [AssignmentStatus::Completed, AssignmentStatus::Failed])->pluck('completed_at')->filter()->sort()->values();
            $pace = $finished->count() >= 2 ? (int) round($finished->first()->diffInMinutes($finished->last()) / ($finished->count() - 1)) : null;
            $habit = $habits[$courier->id] ?? ['pace' => null, 'success_rate' => null];

            // Heure de fin estimée : ce qui reste, au rythme du jour (sinon habituel)
            $estimatedEnd = null;
            $usePace = $pace ?? $habit['pace'];
            if ($isToday && $active->isNotEmpty() && $usePace) {
                $estimatedEnd = now()->addMinutes($active->count() * $usePace);
            } elseif ($active->isEmpty() && $finished->isNotEmpty()) {
                $estimatedEnd = $finished->last();
            }
            $lateEnd = $isToday && $active->isNotEmpty() && $estimatedEnd && $closesAt && $estimatedEnd->gt($closesAt);

            $inHand = (int) ($held->get($courier->id)?->n ?? 0);
            $overdue = $holdLimit ? (int) ($held->get($courier->id)?->overdue ?? 0) : 0;
            $balance = $balances[$courier->id] ?? null;
            $silentFor = $courier->last_location_at ? (int) $courier->last_location_at->diffInMinutes(now()) : null;
            $stillActive = $mine->filter(fn (OrderAssignment $a) => in_array($a->status, AssignmentStatus::active(), true));
            $outOfDelay = $stillActive->filter(fn (OrderAssignment $a) => $a->order?->internal_due_at?->isPast())->count();
            $noReceipt = $mine->filter(fn (OrderAssignment $a) => isset($missing[$a->order_id]) && $a->type === AssignmentType::Delivery && $a->status === AssignmentStatus::Completed)->count();

            $state = match (true) {
                $isFuture => $stillActive->isNotEmpty() ? 'planned' : ($mad ? 'mad' : 'idle'),
                ! $isToday => $finished->isNotEmpty() || $madMissions->isNotEmpty() ? 'done' : 'offline',
                $mad !== null && $mad['reserved_now'] => 'mad',
                $active->isNotEmpty() => 'mission',
                $courier->is_available => 'available',
                default => 'offline',
            };

            $flags = ! $isToday ? [] : array_values(array_filter([
                ($n = (int) ($reports[$courier->user_id] ?? 0)) > 0 ? ($n > 1 ? "{$n} remontées ouvertes" : '1 remontée ouverte') : null,
                $overdue > 0 ? ($overdue > 1 ? "{$overdue} colis gardés trop longtemps" : '1 colis gardé trop longtemps') : null,
                $this->cashFlag($courier, $balance),
                $stillActive->contains(fn (OrderAssignment $a) => $a->unreachable_at !== null || $a->late_alerted_at !== null) ? 'Mission en retard' : null,
                in_array($state, ['mission', 'mad'], true) && $stillActive->isNotEmpty() && ($silentFor === null || $silentFor > self::SILENT_MINUTES)
                    ? 'Pas de position'.($silentFor !== null ? ' depuis '.self::duration($silentFor) : '') : null,
                $lateEnd ? 'Finira après la fermeture du dépôt' : null,
                ($n = $before->count()) > 0 ? ($n > 1 ? "{$n} colis en retard d'avant" : "1 colis en retard d'avant") : null,
                $outOfDelay > 0 ? ($outOfDelay > 1 ? "{$outOfDelay} courses hors délai interne" : '1 course hors délai interne') : null,
                $noReceipt > 0 ? ($noReceipt > 1 ? "{$noReceipt} accusés de réception manquants" : '1 accusé de réception manquant') : null,
            ]));

            $planned = $deliveries['total'] + $pickups['total'];

            return [
                'id' => $courier->id,
                'name' => $courier->user->name,
                'phone' => $courier->user->phone,
                'vehicle_type' => $courier->vehicle_type,
                'zones' => $courier->zones->pluck('name')->values(),
                'state' => $state,
                'is_available' => $courier->is_available,
                'last_location_at' => $isToday ? $courier->last_location_at : null,
                'lat' => $isToday ? $courier->current_lat : null,
                'lng' => $isToday ? $courier->current_lng : null,
                'deliveries' => $deliveries,
                'pickups' => $pickups,
                'failed' => $failed,
                'failed_deliveries' => $failedDeliveries,
                'remaining' => $stillActive->count(),
                'late_before' => $before->count(),
                'out_of_delay' => $outOfDelay,
                'missing_receipts' => $noReceipt,
                'progress' => $planned > 0 ? (int) round(100 * ($deliveries['done'] + $pickups['done']) / $planned) : null,
                'success_rate' => $attempted > 0 ? (int) round(100 * $deliveries['done'] / $attempted) : null,
                'pace' => $pace,
                'habit' => $habit,
                'estimated_end' => $estimatedEnd?->toIso8601String(),
                'late_end' => (bool) $lateEnd,
                'objective' => $isToday ? $this->objective($courier) : null,
                'parcels_in_hand' => $inHand,
                'cash_in_hand' => (int) ($balance['due'] ?? 0),
                // Mise à disposition du jour : client, horaire, réservé en ce moment, courses faites pour lui
                'mad' => $mad ? [
                    'merchant' => $mad['merchant'], 'from' => $mad['from'], 'to' => $mad['to'], 'reserved_now' => $mad['reserved_now'],
                    'done' => $madMissions->where('status', AssignmentStatus::Completed)->count(), 'total' => $madMissions->count(),
                ] : null,
                'activities' => $all->map(fn (OrderAssignment $a) => self::activityOf($a->order))->unique()->values(),
                'flags' => $flags,
            ];
        });

        // Ceux qui demandent l'attention d'abord, puis ceux qui ont le plus à faire
        $rows = $rows->sortBy(fn (array $r) => [-count($r['flags']), in_array($r['state'], ['offline', 'idle'], true) ? 1 : 0, -$r['remaining'], $r['name']])->values();
        if ($activity) {
            // Activité choisie : les livreurs qui y ont travaillé (ou, en MAD, qui y sont)
            $rows = $rows->filter(fn (array $r) => $r['activities']->contains($activity) || ($activity === 'mad' && $r['mad']))->values();
        }

        $deliveries = $rows->sum(fn ($r) => $r['deliveries']['total']);
        $done = $rows->sum(fn ($r) => $r['deliveries']['done']);
        $planned = $deliveries + $rows->sum(fn ($r) => $r['pickups']['total']);
        $achieved = $done + $rows->sum(fn ($r) => $r['pickups']['done']);
        $attempted = $done + $rows->sum('failed_deliveries');
        $habitual = collect($habits)->pluck('success_rate')->filter(fn ($v) => $v !== null);
        $ends = $rows->where('remaining', '>', 0)->pluck('estimated_end')->filter();

        return [
            'day' => $day,
            'is_today' => $isToday,
            'is_future' => $isFuture,
            'activity' => $activity,
            'depot_closes_at' => $company->depot_closes_at ? substr((string) $company->depot_closes_at, 0, 5) : null,
            'data' => $rows->all(),
            'totals' => [
                'couriers' => $rows->count(),
                'active' => $rows->filter(fn ($r) => $isToday ? $r['state'] !== 'offline' : $r['state'] === ($isFuture ? 'planned' : 'done'))->count(),
                'mad' => $rows->filter(fn ($r) => $r['mad'] !== null)->count(),
                'late_before' => (int) $rows->sum('late_before'),
                'out_of_delay' => (int) $rows->sum('out_of_delay'),
                'missing_receipts' => (int) $rows->sum('missing_receipts'),
                'in_hand' => (int) $rows->sum('parcels_in_hand'),
                'delivered' => $done,
                'deliveries' => $deliveries,
                'units_done' => (int) $rows->sum(fn ($r) => $r['deliveries']['units_done']),
                'units_total' => (int) $rows->sum(fn ($r) => $r['deliveries']['units_total']),
                'failed' => (int) $rows->sum('failed'),
                'watched' => $rows->filter(fn ($r) => $r['flags'] !== [])->count(),
                'progress' => $planned > 0 ? (int) round(100 * $achieved / $planned) : null,
                'success_rate' => $attempted > 0 ? (int) round(100 * $done / $attempted) : null,
                'habit_success_rate' => $habitual->isNotEmpty() ? (int) round($habitual->avg()) : null,
                'late_end' => $rows->where('late_end', true)->count(),
                'estimated_end' => $ends->max(),
            ],
            // Lots de distribution en cours : avancement
            'lots' => $activity && $activity !== 'distribution' ? [] : $this->lots($company, $day),
        ];
    }

    /**
     * Détail d'un livreur pour le panneau : ses missions du jour, avec l'adresse de livraison des missions à faire.
     *
     * @return list<array<string, mixed>>
     */
    public function missions(Courier $courier, ?string $date = null): array
    {
        $company = Company::findOrFail($courier->company_id);
        [$localDay, $isToday] = $this->resolveDay($company, $date);
        $day = $localDay->toDateString();
        $since = $localDay->setTimezone(config('app.timezone'));
        $until = $localDay->addDay()->setTimezone(config('app.timezone'));

        return $this->ofDay(OrderAssignment::query()->where('courier_id', $courier->id), $since, $until, $day, $isToday)
            ->with(['order' => fn ($q) => $q->withoutGlobalScopes()->with(['merchant:id,business_name,pickup_lat,pickup_lng', 'deliveryZone:id,name', 'pickupZone:id,name'])])
            ->orderByRaw('completed_at IS NULL')->orderBy('completed_at')->orderBy('id')
            ->get()
            ->map(function (OrderAssignment $a) use ($day) {
                $delivery = $a->type !== AssignmentType::Pickup;
                $order = $a->order;

                return [
                    'id' => $a->id,
                    'order_id' => $a->order_id,
                    'tracking_code' => $order?->tracking_code,
                    'merchant' => $order?->merchant?->business_name,
                    'recipient' => $delivery ? $order?->recipient_name : null,
                    'zone' => $delivery ? $order?->deliveryZone?->name : $order?->pickupZone?->name,
                    'activity' => self::activityOf($order),
                    'units' => self::units($order),
                    'type' => $a->type->value,
                    'type_label' => $a->type->label(),
                    'status' => $a->status->value,
                    'completed_at' => $a->completed_at,
                    'delivery_day' => $order ? self::deliveryDay($order) : null,
                    'from_before' => self::fromBefore($a, $day),
                    'out_of_delay' => in_array($a->status, AssignmentStatus::active(), true) && (bool) $order?->internal_due_at?->isPast(),
                    'cod_amount' => $a->type === AssignmentType::Delivery ? (int) $order?->cod_amount : null,
                    // Où aller : chez le destinataire (livraison, retour) ou chez le client (ramassage)
                    'lat' => $delivery ? $order?->delivery_lat : ($order?->pickup_lat ?? $order?->merchant?->pickup_lat),
                    'lng' => $delivery ? $order?->delivery_lng : ($order?->pickup_lng ?? $order?->merchant?->pickup_lng),
                ];
            })->all();
    }

    /** @return array{0: CarbonImmutable, 1: bool, 2: bool} */
    private function resolveDay(Company $company, ?string $date): array
    {
        $tz = $company->timezone ?: config('app.timezone');
        $localToday = CarbonImmutable::now($tz)->startOfDay();
        $localDay = $date ? CarbonImmutable::parse($date, $tz)->startOfDay() : $localToday;

        return [$localDay, $localDay->equalTo($localToday), $localDay->gt($localToday)];
    }

    /**
     * Missions d'un jour : celles finies ce jour-là, et celles à faire des colis prévus ce jour-là (aujourd'hui, aussi
     * celles des colis prévus avant, encore à faire).
     *
     * @param  Builder<OrderAssignment>  $query
     * @return Builder<OrderAssignment>
     */
    private function ofDay(Builder $query, CarbonImmutable $since, CarbonImmutable $until, string $day, bool $isToday, ?string $activity = null): Builder
    {
        return $query->where(fn ($q) => $q
            ->where(fn ($q) => $q->whereIn('status', [AssignmentStatus::Completed->value, AssignmentStatus::Failed->value])
                ->where('completed_at', '>=', $since)->where('completed_at', '<', $until))
            ->orWhere(fn ($q) => $q->whereIn('status', AssignmentStatus::activeValues())
                ->whereHas('order', fn ($o) => $o->withoutGlobalScopes()->whereRaw(Order::DELIVERY_DAY_SQL.($isToday ? ' <= ?' : ' = ?'), [$day]))))
            ->when($activity, fn ($q) => $q->whereHas('order', fn ($o) => $o->withoutGlobalScopes()->where('activity', $activity)));
    }

    /**
     * Courses de distribution livrées sans la photo de l'accusé (ou de la décharge) demandée — par exemple une
     * livraison faite hors connexion : course => vrai.
     *
     * @param  Collection<int, OrderAssignment>  $assignments
     * @return array<int, bool>
     */
    private function missingReceipts(Collection $assignments): array
    {
        $delivered = $assignments->filter(fn (OrderAssignment $a) => $a->type === AssignmentType::Delivery && $a->status === AssignmentStatus::Completed
            && $a->order?->status === OrderStatus::Delivered && self::activityOf($a->order) !== 'ecommerce');
        if ($delivered->isEmpty()) {
            return [];
        }
        $withPhoto = OrderAttachment::query()->whereIn('order_id', $delivered->pluck('order_id'))->where('type', 'photo_delivery')->pluck('order_id')->flip();

        return $delivered->filter(fn (OrderAssignment $a) => ! isset($withPhoto[$a->order_id]) && ($this->options->proof($a->order)['proof_photo'] ?? false))
            ->mapWithKeys(fn (OrderAssignment $a) => [$a->order_id => true])->all();
    }

    /**
     * Lots de distribution en cours ce jour-là : avancement (livrés, en cours, en retard sur le délai interne).
     *
     * @return list<array<string, mixed>>
     */
    private function lots(Company $company, string $day): array
    {
        $space = app(ClientSpace::class);

        return BillingPlan::forCompany($company->id)->lots()->activeOn($day)->with('merchant:id,business_name')->orderBy('lot_due_on')->limit(20)->get()
            ->map(fn (BillingPlan $lot) => [
                'id' => $lot->id,
                'name' => $lot->lotName(),
                'merchant' => $lot->merchant?->business_name,
                'due_on' => $lot->lot_due_on?->toDateString(),
                ...collect($space->lotProgress($lot))->only(['included', 'used', 'delivered', 'in_progress', 'late', 'done'])->all(),
            ])->filter(fn (array $l) => ! $l['done'])->values()->all();
    }

    /**
     * Habitudes de chaque livreur sur les 30 jours avant le jour suivi : minutes entre deux livraisons et taux de
     * réussite des livraisons.
     *
     * @param  list<int>  $ids
     * @return array<int, array{pace: ?int, success_rate: ?int}>
     */
    private function habits(array $ids, CarbonImmutable $before): array
    {
        $rows = OrderAssignment::query()->whereIn('courier_id', $ids)->where('type', AssignmentType::Delivery->value)
            ->whereIn('status', [AssignmentStatus::Completed->value, AssignmentStatus::Failed->value])
            ->where('completed_at', '>=', $before->subDays(self::HABIT_DAYS))->where('completed_at', '<', $before)
            ->get(['courier_id', 'status', 'completed_at']);

        return $rows->groupBy('courier_id')->map(function (Collection $list) {
            $paces = $list->groupBy(fn ($a) => $a->completed_at->toDateString())
                ->filter(fn (Collection $day) => $day->count() >= 2)
                ->map(fn (Collection $day) => $day->min('completed_at')->diffInMinutes($day->max('completed_at')) / ($day->count() - 1));
            $completed = $list->where('status', AssignmentStatus::Completed)->count();

            return [
                'pace' => $paces->isNotEmpty() ? (int) round($paces->avg()) : null,
                'success_rate' => (int) round(100 * $completed / $list->count()),
            ];
        })->all();
    }

    /**
     * Prochain palier de prime sur les livraisons (période de paie en cours), s'il y en a un.
     *
     * @return array{value: int, threshold: int, amount: int, label: string}|null
     */
    private function objective(Courier $courier): ?array
    {
        $plan = app(CourierPay::class)->planFor($courier);
        $period = $plan?->periodContaining(today());
        if ($plan === null || $period === null) {
            return null;
        }
        $plan->loadMissing('bonuses');
        $tiers = $plan->bonuses->filter(fn ($b) => $b->metric === PayBonusMetric::Deliveries)->sortBy('threshold')->values();
        if ($tiers->isEmpty()) {
            return null;
        }

        $value = (int) (app(PayBonuses::class)->measure($courier, $period[0], $period[1])['deliveries'] ?? 0);
        $next = $tiers->first(fn ($t) => $t->threshold > $value) ?? $tiers->last();

        return ['value' => $value, 'threshold' => $next->threshold, 'amount' => $next->amount, 'label' => $plan->periodLabel() ?? 'période'];
    }

    /** Argent à surveiller : au-delà du plafond du livreur, sinon gardé plus d'un jour */
    private function cashFlag(Courier $courier, ?array $balance): ?string
    {
        if (! $balance || $balance['due'] <= 0) {
            return null;
        }
        if ($courier->max_cash && $balance['due'] > $courier->max_cash) {
            return 'Argent au-delà de son plafond';
        }
        if ($balance['oldest'] && CarbonImmutable::parse($balance['oldest'])->lt(now()->subHours(self::CASH_HOURS))) {
            return 'Argent gardé depuis plus d\'un jour';
        }

        return null;
    }

    private static function activityOf(?Order $order): string
    {
        $activity = $order?->activity;

        return $activity instanceof Activity ? $activity->value : ($activity ?: 'ecommerce');
    }

    /** Envois d'une course : plis et chèques comptés un à un (distribution), sinon 1 */
    private static function units(?Order $order): int
    {
        if ($order === null) {
            return 0;
        }

        $type = $order->shipment_type instanceof ShipmentType ? $order->shipment_type->value : $order->shipment_type;

        return self::activityOf($order) === 'distribution' && in_array($type, ['pli', 'cheque'], true)
            ? max(1, (int) $order->shipment_quantity) : 1;
    }

    /** Jour de livraison du colis : la date prévue, sinon le jour de création (comme Order::DELIVERY_DAY_SQL) */
    private static function deliveryDay(Order $order): string
    {
        return ($order->delivery_scheduled_date ?? $order->created_at)->toDateString();
    }

    /** Mission encore à faire d'un colis prévu avant ce jour */
    private static function fromBefore(OrderAssignment $a, string $day): bool
    {
        return in_array($a->status, AssignmentStatus::active(), true) && $a->order !== null && self::deliveryDay($a->order) < $day;
    }

    /** « 25 min », « 3 h », « 2 jours » */
    public static function duration(int $minutes): string
    {
        return match (true) {
            $minutes < 60 => $minutes.' min',
            $minutes < 48 * 60 => intdiv($minutes, 60).' h',
            default => intdiv($minutes, 1440).' jours',
        };
    }
}
