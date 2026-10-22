<?php

namespace App\Services\Finance;

use App\Enums\EarningType;
use App\Enums\PayEvent;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\Order;
use App\Models\PayPlan;
use App\Models\PayPlanBonus;
use App\Models\PayPlanRule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Simulateur de paie : calcule, sans rien enregistrer, ce qu'un plan (même non
 * enregistré) donnerait sur une course fictive, ou sur l'activité réelle des
 * livreurs pendant une période passée, comparé à ce qu'ils ont réellement gagné.
 */
class PaySimulator
{
    private const COURSE_TYPES = [EarningType::Pickup, EarningType::Delivery, EarningType::FailedAttempt, EarningType::Return];

    public function __construct(private CourierPay $pay, private CourierActivity $activity, private PayBonuses $bonuses) {}

    /**
     * Plan en mémoire à partir des réglages de l'éditeur.
     *
     * @param  array<string, mixed>  $data
     */
    public function plan(int $companyId, array $data): PayPlan
    {
        $plan = new PayPlan([
            'company_id' => $companyId,
            'name' => $data['name'] ?? 'Simulation',
            'pickup_mode' => $data['pickup_mode'] ?? PayPlan::PICKUP_PER_PARCEL,
            'pickup_extra_parcel_amount' => $data['pickup_extra_parcel_amount'] ?? 0,
            'min_amount' => $data['min_amount'] ?? null,
            'max_amount' => $data['max_amount'] ?? null,
            'base_salary' => $data['base_salary'] ?? 0,
            'pay_period' => $data['pay_period'] ?? null,
        ]);

        $plan->setRelation('rules', collect($data['rules'] ?? [])->values()->map(fn ($r, $i) => new PayPlanRule([
            'event' => $r['event'], 'calc' => $r['calc'], 'amount' => (int) ($r['amount'] ?? 0), 'percent' => $r['percent'] ?? null,
            'zone_amounts' => $r['zone_amounts'] ?? null, 'conditions' => $r['conditions'] ?? null, 'label' => $r['label'] ?? null, 'sort_order' => $i,
        ])));
        $plan->setRelation('bonuses', collect($data['bonuses'] ?? [])->map(fn ($b) => new PayPlanBonus([
            'metric' => $b['metric'], 'threshold' => (int) $b['threshold'], 'min_count' => $b['min_count'] ?? null,
            'amount' => (int) $b['amount'], 'label' => $b['label'] ?? null,
        ])));

        return $plan;
    }

    /**
     * Une étape d'une course fictive.
     *
     * @param  array<string, mixed>  $scenario
     * @return array{lines: list<array{label: string, amount: int, detail: string}>, total: int}
     */
    public function scenario(PayPlan $plan, array $scenario): array
    {
        $order = (new Order)->forceFill([
            'pickup_zone_id' => $scenario['zone_id'] ?? null,
            'delivery_zone_id' => $scenario['zone_id'] ?? null,
            'delivery_fee' => (int) ($scenario['fees'] ?? 0),
            'surcharges_total' => 0,
            'cod_amount' => (int) ($scenario['collected'] ?? 0),
            'is_express' => (bool) ($scenario['express'] ?? false),
            'is_fragile' => (bool) ($scenario['fragile'] ?? false),
            'last_incident_reason_id' => $scenario['incident_reason_id'] ?? null,
        ]);
        $courier = (new Courier)->forceFill(['company_id' => $plan->company_id, 'vehicle_type' => $scenario['vehicle_type'] ?? 'moto']);

        $tz = $this->activity->timezone($plan->company_id);
        $at = isset($scenario['at']) ? CarbonImmutable::parse($scenario['at'], $tz) : now();
        $lines = $this->pay->lines($plan, PayEvent::from($scenario['event']), $order, $courier, at: $at, sameVisit: (bool) ($scenario['same_visit'] ?? false));

        return [
            'lines' => array_map(fn ($l) => ['label' => $l['label'], 'amount' => $l['amount'], 'detail' => $l['detail']], $lines),
            'total' => array_sum(array_column($lines, 'amount')),
        ];
    }

    /**
     * Rejoue l'activité réelle des livreurs sur une période avec le plan.
     *
     * @return array{period_start: string, period_end: string, couriers: list<array<string, mixed>>, totals: array{simulated: int, actual: int}}
     */
    public function replay(PayPlan $plan, CarbonInterface $start, CarbonInterface $end): array
    {
        $couriers = Courier::query()->with('user')
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->get()->keyBy('id');
        $events = $this->activity->events($plan->company_id, $couriers->keys()->all(), $start, $end)->groupBy('courier_id');
        $orders = Order::query()->withoutGlobalScopes()->whereIn('id', $events->flatten(1)->pluck('order_id')->unique())->get()->keyBy('id');
        [$from, $to] = $this->activity->range($plan->company_id, $start, $end);
        $tz = $this->activity->timezone($plan->company_id);

        $rows = $couriers->map(function (Courier $courier) use ($plan, $events, $orders, $from, $to, $start, $end, $tz) {
            $mine = $events->get($courier->id, collect());
            $courses = 0;
            $counts = [];
            $visits = [];   // dernier ramassage payé plein, par marchand
            $failed = [];   // tentative ratée payée, par course et par jour

            foreach ($mine as $e) {
                $order = $orders[$e['order_id']] ?? null;
                if ($order === null) {
                    continue;
                }
                $counts[$e['event']->value] = ($counts[$e['event']->value] ?? 0) + 1;

                if ($e['event'] === PayEvent::FailedAttempt) {
                    $key = $order->id.'-'.$e['at']->setTimezone($tz)->toDateString();
                    if (isset($failed[$key])) {
                        continue;
                    }
                    $failed[$key] = true;
                }

                $sameVisit = false;
                if ($e['event'] === PayEvent::Pickup) {
                    $last = $visits[$order->merchant_id] ?? null;
                    $sameVisit = $last !== null && $last->diffInHours($e['at']) < CourierPay::VISIT_WINDOW_HOURS;
                    if (! $sameVisit) {
                        $visits[$order->merchant_id] = $e['at'];
                    }
                }

                $order->collected_amount = $e['collected'] ?? $order->collected_amount;
                $order->last_incident_reason_id = $e['incident_reason_id'] ?? $order->last_incident_reason_id;
                $courses += array_sum(array_column($this->pay->lines($plan, $e['event'], $order, $courier, at: $e['at'], sameVisit: $sameVisit), 'amount'));
            }

            $measures = $this->activity->measures($mine, $tz);
            $bonuses = $this->bonuses->reached($plan->bonuses, $measures)
                ->map(fn (PayPlanBonus $b) => ['label' => $b->title(), 'amount' => $b->amount])->values();
            $salary = $plan->pay_period ? $plan->base_salary : 0;

            $actual = (int) CourierEarning::query()->where('courier_id', $courier->id)
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->whereIn('type', self::COURSE_TYPES)->whereBetween('created_at', [$from, $to]))
                    ->orWhere(fn ($q) => $q->whereIn('type', [EarningType::Salary, EarningType::Bonus])
                        ->whereBetween('period_start', [$start->toDateString(), $end->toDateString()])))
                ->sum('amount');

            return [
                'courier_id' => $courier->id,
                'name' => $courier->user?->name,
                'current_plan_id' => $courier->pay_plan_id,
                'counts' => $counts,
                'measures' => $measures,
                'courses' => $courses,
                'bonuses' => $bonuses,
                'salary' => $salary,
                'simulated' => $courses + $bonuses->sum('amount') + $salary,
                'actual' => $actual,
            ];
        })->sortByDesc('simulated')->values();

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'couriers' => $rows->all(),
            'totals' => ['simulated' => $rows->sum('simulated'), 'actual' => $rows->sum('actual')],
        ];
    }
}
