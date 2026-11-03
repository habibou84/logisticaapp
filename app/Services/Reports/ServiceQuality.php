<?php

namespace App\Services\Reports;

use App\Enums\BillingFormula;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Models\BillingPlan;
use App\Models\Company;
use App\Models\Courier;
use App\Models\DailySnapshot;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\OrderEvent;
use App\Models\Zone;
use App\Services\Couriers\DailyActivity;
use App\Services\Finance\MadDays;
use App\Services\Orders\ActivityOptions;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Qualité de service (Statistiques › Délais et échecs, Distribution) :
 * - respect des délais : e-commerce livré au plus tard le jour prévu ; distribution avant son délai interne ; lots
 *   terminés avant leur échéance ; MAD : jours de présence faits sur prévus ; par client, zone et livreur ;
 * - échecs : par motif, motif × zone, heure, livreur et client ;
 * - distribution : plis remis par jour, accusés manquants, temps par pli, avancement des lots contre la courbe idéale ;
 * - historique des courses non traitées (relevé chaque soir).
 */
class ServiceQuality
{
    public function __construct(
        private readonly ActivityOptions $options,
        private readonly MadDays $madDays,
    ) {}

    /** @return array<string, mixed> */
    public function build(Company $company, CarbonImmutable $from, CarbonImmutable $to, ?string $activity = null): array
    {
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');
        $start = CarbonImmutable::parse($from->toDateString(), $tz)->startOfDay();
        $end = CarbonImmutable::parse($to->toDateString(), $tz)->endOfDay();
        $orders = fn () => Order::forCompany($company->id)->when($activity, fn ($q) => $q->where('activity', $activity));

        $delivered = $orders()->where('status', OrderStatus::Delivered)->whereBetween('delivered_at', [$start->utc(), $end->utc()])
            ->get(['id', 'merchant_id', 'delivery_zone_id', 'delivery_courier_id', 'activity', 'shipment_type', 'shipment_quantity',
                'delivery_scheduled_date', 'created_at', 'delivered_at', 'picked_up_at', 'internal_due_at', 'billing_plan_id']);
        $activityOf = fn (Order $o) => DailyActivity::activityOf($o);
        $localDay = fn ($moment) => CarbonImmutable::parse($moment)->setTimezone($tz)->toDateString();

        // À l'heure : e-commerce au plus tard le jour prévu ; distribution et MAD avant le délai interne (sinon le jour prévu)
        $onTime = fn (Order $o) => $o->internal_due_at !== null && $activityOf($o) !== 'ecommerce'
            ? $o->delivered_at->lessThanOrEqualTo($o->internal_due_at)
            : $localDay($o->delivered_at) <= ($o->delivery_scheduled_date ?? $o->created_at)->toDateString();
        $names = $this->names($company, $delivered);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'delays' => [
                'by_activity' => $this->rates($delivered, $activityOf, $onTime, fn ($k) => ['ecommerce' => 'E-commerce', 'distribution' => 'Distribution', 'mad' => 'MAD'][$k] ?? $k),
                'by_client' => $this->rates($delivered, fn (Order $o) => $o->merchant_id, $onTime, fn ($k) => $names['merchants'][$k] ?? 'Client'),
                'by_zone' => $this->rates($delivered, fn (Order $o) => $o->delivery_zone_id, $onTime, fn ($k) => $names['zones'][$k] ?? 'Sans zone'),
                'by_courier' => $this->rates($delivered, fn (Order $o) => $o->delivery_courier_id, $onTime, fn ($k) => $names['couriers'][$k] ?? 'Sans livreur'),
                'total' => $delivered->count(),
                'on_time' => $delivered->filter($onTime)->count(),
                'rate' => $this->rate($delivered->filter($onTime)->count(), $delivered->count()),
            ],
            'lots' => in_array($activity, [null, 'distribution'], true) ? $this->lots($company, $start, $end, $tz) : [],
            'mad' => in_array($activity, [null, 'mad'], true) ? $this->mad($company, $start, $end) : [],
            'failures' => $this->failures($company, $start, $end, $tz, $orders, $delivered, $names),
            'distribution' => in_array($activity, [null, 'distribution'], true) ? $this->distribution($company, $start, $end, $delivered, $tz) : null,
            'history' => $this->history($company, $start, $end),
        ];
    }

    /**
     * Taux « à l'heure » par groupe, du moins bon au meilleur (groupes de 3 livraisons au moins).
     *
     * @param  Collection<int, Order>  $orders
     * @return list<array{key: string, label: string, total: int, on_time: int, late: int, rate: ?float}>
     */
    private function rates(Collection $orders, callable $group, callable $onTime, callable $label): array
    {
        return $orders->groupBy($group)->map(function (Collection $list, $key) use ($onTime, $label) {
            $ok = $list->filter($onTime)->count();

            return ['key' => (string) $key, 'label' => $label($key === '' ? null : $key), 'total' => $list->count(), 'on_time' => $ok, 'late' => $list->count() - $ok, 'rate' => $this->rate($ok, $list->count())];
        })->sortBy('rate')->values()->all();
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return array{merchants: Collection, zones: Collection, couriers: Collection}
     */
    private function names(Company $company, Collection $orders, ?Collection $extraCouriers = null): array
    {
        return [
            'merchants' => Merchant::withoutGlobalScopes()->whereIn('id', $orders->pluck('merchant_id')->unique())->pluck('business_name', 'id'),
            'zones' => Zone::withoutGlobalScopes()->whereIn('id', $orders->pluck('delivery_zone_id')->filter()->unique())->pluck('name', 'id'),
            'couriers' => Courier::withoutGlobalScopes()->where('company_id', $company->id)->with('user:id,name')->get()->mapWithKeys(fn (Courier $c) => [$c->id => $c->user?->name]),
        ];
    }

    /**
     * Lots dont l'échéance tombe dans la période (ou est passée et le lot pas terminé) : terminé à temps, en retard, en cours.
     *
     * @return list<array<string, mixed>>
     */
    private function lots(Company $company, CarbonImmutable $start, CarbonImmutable $end, string $tz): array
    {
        $lots = BillingPlan::forCompany($company->id)->lots()->whereNotNull('lot_due_on')
            ->whereDate('lot_due_on', '>=', $start->toDateString())->whereDate('lot_due_on', '<=', $end->toDateString())
            ->with('merchant:id,business_name')->orderBy('lot_due_on')->get();

        return $lots->map(function (BillingPlan $lot) use ($tz) {
            $orders = Order::withoutGlobalScopes()->where('billing_plan_id', $lot->id)->whereNull('deleted_at')
                ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Rejected->value])->get(['status', 'delivered_at']);
            $open = $orders->filter(fn (Order $o) => ! $o->status->isFinal())->count();
            $last = $orders->max('delivered_at');
            $lastDay = $last ? CarbonImmutable::parse($last)->setTimezone($tz)->toDateString() : null;
            $due = $lot->lot_due_on->toDateString();
            $status = $open > 0 || $orders->count() < (int) $lot->lot_quantity
                ? ($due < now($tz)->toDateString() ? 'late' : 'in_progress')
                : ($lastDay !== null && $lastDay <= $due ? 'on_time' : 'late');

            return [
                'id' => $lot->id, 'name' => $lot->lotName(), 'merchant' => $lot->merchant?->business_name, 'due_on' => $due,
                'quantity' => (int) $lot->lot_quantity, 'delivered' => $orders->where('status', OrderStatus::Delivered)->count(),
                'open' => $open, 'finished_on' => $open === 0 ? $lastDay : null, 'status' => $status,
            ];
        })->values()->all();
    }

    /**
     * MAD : jours de présence prévus (jours du contrat × livreurs) et faits (hors absences), par contrat.
     *
     * @return list<array<string, mixed>>
     */
    private function mad(Company $company, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $today = CarbonImmutable::now($company->timezone ?: config('app.timezone'))->startOfDay();
        $last = $end->startOfDay()->min($today);
        if ($last->lessThan($start->startOfDay())) {
            return [];
        }

        return BillingPlan::forCompany($company->id)->where('formula', BillingFormula::MadContract->value)
            ->overlapping($start->toDateString(), $last->toDateString())->with(['merchant:id,business_name', 'couriers.user:id,name'])->get()
            ->map(function (BillingPlan $plan) use ($company, $start, $last) {
                $weekdays = array_map('intval', $this->options->plan($plan, $company)['mad_days']);
                $first = $start->startOfDay()->max(CarbonImmutable::parse($plan->starts_on->toDateString()));
                $stop = $plan->ends_on ? $last->min(CarbonImmutable::parse($plan->ends_on->toDateString())) : $last;
                $days = $stop->lessThan($first) ? 0 : collect(CarbonPeriod::create($first, $stop))->filter(fn ($d) => in_array($d->dayOfWeekIso, $weekdays, true))->count();
                $planned = $days * $plan->couriers->count();
                $done = (int) $plan->couriers->sum(fn (Courier $c) => $this->madDays->count($c, $first, $stop)['total']);

                return [
                    'id' => $plan->id, 'merchant' => $plan->merchant?->business_name, 'couriers' => $plan->couriers->map(fn ($c) => $c->user?->name)->filter()->values()->all(),
                    'planned_days' => $planned, 'done_days' => min($done, $planned), 'rate' => $this->rate(min($done, $planned), $planned),
                ];
            })->values()->all();
    }

    /**
     * Échecs de livraison (la course quitte « En chemin » avec un motif) : motifs, motif × zone, heures, livreurs,
     * clients (avec leur motif le plus fréquent).
     *
     * @param  Collection<int, Order>  $delivered
     * @param  array<string, Collection>  $names
     * @return array<string, mixed>
     */
    private function failures(Company $company, CarbonImmutable $start, CarbonImmutable $end, string $tz, callable $orders, Collection $delivered, array $names): array
    {
        $events = OrderEvent::query()->whereIn('order_events.order_id', $orders()->select('id'))
            ->where('type', OrderEventType::Incident->value)->where('from_status', OrderStatus::OutForDelivery->value)
            ->whereIn('to_status', [OrderStatus::DeliveryFailed->value, OrderStatus::Rescheduled->value])
            ->whereBetween('created_at', [$start->utc(), $end->utc()])
            ->with(['incidentReason:id,label', 'order' => fn ($q) => $q->withoutGlobalScopes()->select(['id', 'merchant_id', 'delivery_zone_id', 'delivery_courier_id']), 'assignment:id,courier_id'])
            ->get(['id', 'order_id', 'assignment_id', 'incident_reason_id', 'created_at']);
        $reason = fn (OrderEvent $e) => $e->incidentReason?->label ?? 'Sans motif';
        $zoneNames = Zone::withoutGlobalScopes()->whereIn('id', $events->pluck('order.delivery_zone_id')->filter()->unique())->pluck('name', 'id')->union($names['zones']);
        $merchantNames = Merchant::withoutGlobalScopes()->whereIn('id', $events->pluck('order.merchant_id')->unique())->pluck('business_name', 'id')->union($names['merchants']);
        $courierOf = fn (OrderEvent $e) => $e->assignment?->courier_id ?? $e->order?->delivery_courier_id;

        $reasons = $events->groupBy($reason)->map(fn (Collection $g, $label) => ['label' => $label, 'count' => $g->count()])->sortByDesc('count')->values();
        $topReasons = $reasons->take(6)->pluck('label')->all();
        $topZones = $events->groupBy(fn ($e) => $e->order?->delivery_zone_id)->sortByDesc(fn ($g) => $g->count())->take(10);
        $matrix = $topZones->map(fn (Collection $g, $zone) => [
            'zone' => $zoneNames[$zone] ?? 'Sans zone',
            'total' => $g->count(),
            'counts' => array_map(fn ($r) => $g->filter(fn ($e) => $reason($e) === $r)->count(), $topReasons),
        ])->values()->all();
        $hours = array_fill(0, 24, 0);
        foreach ($events as $e) {
            $hours[(int) CarbonImmutable::parse($e->created_at)->setTimezone($tz)->format('G')]++;
        }
        $deliveredBy = fn (callable $key) => $delivered->groupBy($key)->map->count();
        $group = function (callable $key, Collection $labels, Collection $successes) use ($events, $reason) {
            return $events->groupBy($key)->map(function (Collection $g, $k) use ($labels, $successes, $reason) {
                $ok = (int) ($successes[$k] ?? 0);

                return [
                    'key' => (string) $k, 'label' => $labels[$k] ?? '—', 'failures' => $g->count(), 'delivered' => $ok,
                    'failure_rate' => $this->rate($g->count(), $g->count() + $ok),
                    'top_reason' => $g->groupBy($reason)->sortByDesc(fn ($x) => $x->count())->keys()->first(),
                ];
            })->sortByDesc('failures')->take(30)->values()->all();
        };

        return [
            'total' => $events->count(),
            'reasons' => $reasons->all(),
            'matrix' => ['reasons' => $topReasons, 'rows' => $matrix],
            'hours' => $hours,
            'by_courier' => $group($courierOf, $names['couriers'], $deliveredBy(fn (Order $o) => $o->delivery_courier_id)),
            'by_client' => $group(fn ($e) => $e->order?->merchant_id, $merchantNames, $deliveredBy(fn (Order $o) => $o->merchant_id)),
            'by_zone' => $group(fn ($e) => $e->order?->delivery_zone_id, $zoneNames, $deliveredBy(fn (Order $o) => $o->delivery_zone_id)),
        ];
    }

    /**
     * Distribution : plis et chèques remis par jour, accusés manquants, temps médian par pli, lots en cours contre la
     * courbe idéale (régulière du début à l'échéance).
     *
     * @param  Collection<int, Order>  $delivered
     * @return array<string, mixed>
     */
    private function distribution(Company $company, CarbonImmutable $start, CarbonImmutable $end, Collection $delivered, string $tz): array
    {
        $list = $delivered->filter(fn (Order $o) => DailyActivity::activityOf($o) === 'distribution');
        $byDay = [];
        foreach (CarbonPeriod::create($start->startOfDay(), $end->startOfDay()) as $d) {
            $byDay[$d->toDateString()] = ['date' => $d->toDateString(), 'units' => 0, 'courses' => 0];
        }
        foreach ($list as $o) {
            $day = CarbonImmutable::parse($o->delivered_at)->setTimezone($tz)->toDateString();
            if (isset($byDay[$day])) {
                $byDay[$day]['units'] += DailyActivity::units($o);
                $byDay[$day]['courses']++;
            }
        }
        $withPhoto = $list->isEmpty() ? collect() : OrderAttachment::query()->whereIn('order_id', $list->pluck('id'))->where('type', 'photo_delivery')->pluck('order_id')->flip();
        $missing = $list->filter(fn (Order $o) => ! isset($withPhoto[$o->id]) && ($this->options->proof($o)['proof_photo'] ?? false))->count();
        $perUnit = $list->filter(fn (Order $o) => $o->picked_up_at && $o->delivered_at->greaterThanOrEqualTo($o->picked_up_at))
            ->map(fn (Order $o) => (int) round($o->picked_up_at->diffInMinutes($o->delivered_at) / max(1, DailyActivity::units($o))))->sort()->values();

        return [
            'units' => (int) $list->sum(fn (Order $o) => DailyActivity::units($o)),
            'courses' => $list->count(),
            'missing_receipts' => $missing,
            'minutes_per_unit' => $perUnit->isEmpty() ? null : $perUnit[intdiv($perUnit->count(), 2)],
            'by_day' => array_values($byDay),
            'lots' => $this->lotCurves($company, $tz),
        ];
    }

    /**
     * Lots en cours : livrés cumulés jour par jour contre la courbe idéale jusqu'à l'échéance.
     *
     * @return list<array<string, mixed>>
     */
    private function lotCurves(Company $company, string $tz): array
    {
        $today = CarbonImmutable::now($tz)->startOfDay();

        return BillingPlan::forCompany($company->id)->lots()->activeOn($today->toDateString())->whereNotNull('lot_due_on')
            ->with('merchant:id,business_name')->orderBy('lot_due_on')->limit(8)->get()
            ->map(function (BillingPlan $lot) use ($tz, $today) {
                $from = CarbonImmutable::parse($lot->starts_on->toDateString());
                $due = CarbonImmutable::parse($lot->lot_due_on->toDateString());
                $span = max(1, (int) $from->diffInDays($due) + 1);
                $quantity = (int) $lot->lot_quantity;
                $delivered = Order::withoutGlobalScopes()->where('billing_plan_id', $lot->id)->where('status', OrderStatus::Delivered->value)
                    ->get(['delivered_at'])->map(fn ($o) => CarbonImmutable::parse($o->delivered_at)->setTimezone($tz)->toDateString())->countBy();
                $points = [];
                $sum = 0;
                foreach (CarbonPeriod::create($from, $due->max($today)) as $i => $d) {
                    $day = $d->toDateString();
                    $sum += (int) ($delivered[$day] ?? 0);
                    $points[] = ['date' => $day, 'ideal' => (int) round($quantity * min(1, ($i + 1) / $span)), 'delivered' => $d->greaterThan($today) ? null : $sum];
                }
                $idealToday = (int) round($quantity * min(1, ((int) $from->diffInDays($today) + 1) / $span));

                return [
                    'id' => $lot->id, 'name' => $lot->lotName(), 'merchant' => $lot->merchant?->business_name, 'due_on' => $due->toDateString(),
                    'quantity' => $quantity, 'delivered' => $sum, 'ideal_today' => $idealToday, 'gap' => $sum - $idealToday, 'points' => $points,
                ];
            })->values()->all();
    }

    /**
     * Courses non traitées, délai interne dépassé et sans livreur, relevés chaque soir (et le moment présent).
     *
     * @return list<array<string, mixed>>
     */
    private function history(Company $company, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return DailySnapshot::forCompany($company->id)->whereBetween('day', [$start->toDateString(), $end->toDateString()])->orderBy('day')->get()
            ->map(fn (DailySnapshot $s) => ['date' => $s->day->toDateString(), ...$s->data])->values()->all();
    }

    private function rate(int $part, int $total): ?float
    {
        return $total > 0 ? round($part / $total * 100, 1) : null;
    }
}
