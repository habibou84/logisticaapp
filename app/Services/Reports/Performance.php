<?php

namespace App\Services\Reports;

use App\Enums\LedgerEntryType;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Models\BillingPlan;
use App\Models\Company;
use App\Models\CourierEarning;
use App\Models\FixedCost;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderExpense;
use App\Models\VehicleLog;
use Carbon\CarbonImmutable;

/**
 * Objectifs et tendances (Statistiques › Objectifs) : les 13 derniers mois (courses créées et livrées, taux de
 * réussite, chiffre d'affaires, coûts, marge), le mois en cours comparé à ses objectifs avec la projection de fin de
 * mois au rythme actuel, au même mois l'an dernier et à la moyenne des 3 mois précédents.
 */
class Performance
{
    public function __construct(private readonly StatsSettings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Company $company, bool $withAmounts, ?string $activity = null): array
    {
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($tz);
        $first = $now->startOfMonth()->subMonths(12);
        $months = [];
        for ($m = $first; $m->lessThanOrEqualTo($now); $m = $m->addMonth()) {
            $months[$m->format('Y-m')] = [
                'month' => $m->format('Y-m'),
                'label' => ucfirst($m->locale('fr')->isoFormat('MMM YYYY')),
                'created' => 0, 'delivered' => 0, 'failed' => 0, 'success_rate' => null,
                'revenue' => 0, 'costs' => 0, 'margin' => 0,
                // Parts fixes (forfaits, lots, frais fixes) : pas multipliées dans la projection
                'fixed_revenue' => 0, 'fixed_costs' => 0,
            ];
        }
        $range = [$first->utc(), $now->endOfDay()->utc()];
        $key = fn ($moment) => CarbonImmutable::parse($moment, 'UTC')->setTimezone($tz)->format('Y-m');
        $add = function (string $month, string $field, int|float $amount) use (&$months) {
            if (isset($months[$month])) {
                $months[$month][$field] += $amount;
            }
        };
        $orders = fn () => Order::forCompany($company->id)->when($activity, fn ($q) => $q->where('activity', $activity));

        $orders()->whereBetween('created_at', $range)->get(['created_at'])->each(fn ($o) => $add($key($o->created_at), 'created', 1));
        $orders()->where('status', OrderStatus::Delivered)->whereBetween('delivered_at', $range)->get(['delivered_at', 'activity', 'billing_amount'])
            ->each(function ($o) use ($add, $key, $withAmounts) {
                $add($key($o->delivered_at), 'delivered', 1);
                $activity = $o->activity instanceof \BackedEnum ? $o->activity->value : $o->activity;
                if ($withAmounts && $activity !== 'ecommerce') {
                    $add($key($o->delivered_at), 'revenue', (int) $o->billing_amount);
                }
            });
        // Échecs de livraison (la course quitte « En chemin » avec un motif)
        OrderEvent::query()->whereIn('order_id', $orders()->select('id'))->where('type', OrderEventType::Incident->value)
            ->where('from_status', OrderStatus::OutForDelivery->value)->whereIn('to_status', [OrderStatus::DeliveryFailed->value, OrderStatus::Rescheduled->value])
            ->whereBetween('created_at', $range)->get(['created_at'])->each(fn ($e) => $add($key($e->created_at), 'failed', 1));

        $full = ['revenue' => 0, 'costs' => 0];
        if ($withAmounts) {
            $full = $this->amounts($company, $activity, $first, $now, $range, $key, $add, $months);
        }
        foreach ($months as &$m) {
            $m['success_rate'] = $m['delivered'] + $m['failed'] > 0 ? round($m['delivered'] / ($m['delivered'] + $m['failed']) * 100, 1) : null;
            $m['margin'] = $m['revenue'] - $m['costs'];
            if (! $withAmounts) {
                $m['revenue'] = $m['costs'] = $m['margin'] = null;
            }
        }
        unset($m);
        $fixedNow = end($months);
        $fixedNow = ['revenue' => $fixedNow['fixed_revenue'], 'costs' => $fixedNow['fixed_costs']];
        $months = array_map(function (array $m) {
            unset($m['fixed_revenue'], $m['fixed_costs']);

            return $m;
        }, $months);

        $list = array_values($months);
        $current = end($list);
        $lastYear = $list[0] ?? null;
        $previous = array_slice($list, -4, 3);
        $avg = fn (string $field) => count($previous) && $previous[0][$field] !== null
            ? round(array_sum(array_column($previous, $field)) / count($previous), 1) : null;
        $elapsed = $now->day;
        $daysInMonth = $now->daysInMonth;
        $scale = fn ($value) => (int) round($value * $daysInMonth / max(1, $elapsed));
        // Fin de mois : la part variable au rythme actuel, plus les forfaits et frais fixes du mois entier
        $projectRevenue = fn ($v) => $v === null ? null : $scale($v - $fixedNow['revenue']) + $full['revenue'];
        $projectCosts = fn ($costs) => $scale($costs - $fixedNow['costs']) + $full['costs'];
        $project = fn ($value) => $value === null ? null : $scale($value);
        $goals = $this->settings->for($company)['goals'];

        $projectField = fn (string $field, $value) => match ($field) {
            'revenue' => $projectRevenue($value),
            'margin' => $value === null ? null : $projectRevenue($current['revenue']) - $projectCosts($current['costs']),
            default => $project($value),
        };
        $goal = function (string $field, $realized, bool $projected) use ($goals, $projectField, $withAmounts) {
            if (! $withAmounts && in_array($field, ['revenue', 'margin'], true)) {
                return null;
            }
            $target = $goals[$field] ?? null;
            $projection = $projected ? $projectField($field, $realized) : $realized;

            return [
                'target' => $target,
                'realized' => $realized,
                'projection' => $projection,
                'percent' => $target ? (int) round($realized / $target * 100) : null,
                'on_track' => $target === null || $projection === null ? null : $projection >= $target,
            ];
        };

        return [
            'months' => $list,
            'current' => [
                'month' => $current['month'],
                'label' => $current['label'],
                'elapsed_days' => $elapsed,
                'days_in_month' => $daysInMonth,
                'goals' => [
                    'delivered' => $goal('delivered', $current['delivered'], true),
                    'revenue' => $goal('revenue', $current['revenue'], true),
                    'success_rate' => $goal('success_rate', $current['success_rate'], false),
                    'margin' => $goal('margin', $current['margin'], true),
                ],
            ],
            'comparisons' => collect(['created', 'delivered', 'success_rate', 'revenue', 'margin'])->mapWithKeys(fn (string $f) => [$f => [
                'current' => $current[$f],
                // Mois en cours ramené au mois entier pour comparer à des mois terminés
                'projection' => $f === 'success_rate' ? $current[$f] : $projectField($f, $current[$f]),
                'last_year' => $lastYear[$f] ?? null,
                'last_3_months' => $avg($f),
            ]])->all(),
            'goals_set' => collect($goals)->filter(fn ($v) => $v !== null)->isNotEmpty(),
        ];
    }

    /**
     * Chiffre d'affaires et coûts mois par mois (mêmes règles que la rentabilité, sans répartition).
     *
     * @param  array<string, array<string, mixed>>  $months
     */
    /**
     * @return array{revenue: int, costs: int} forfaits et frais fixes du mois en cours entier (projection)
     */
    private function amounts(Company $company, ?string $activity, CarbonImmutable $first, CarbonImmutable $now, array $range, callable $key, callable $add, array &$months): array
    {
        $current = $now->format('Y-m');
        $full = ['revenue' => 0, 'costs' => 0];
        $today = $now->startOfDay();
        $orderIds = fn () => Order::forCompany($company->id)->when($activity, fn ($q) => $q->where('activity', $activity))->select('id');

        if ($activity === null || $activity === 'ecommerce') {
            MerchantLedgerEntry::forCompany($company->id)->whereBetween('created_at', $range)
                ->whereIn('type', [LedgerEntryType::DeliveryFee->value, LedgerEntryType::ReturnFee->value, LedgerEntryType::StorageFee->value])
                ->when($activity, fn ($q) => $q->where(fn ($q) => $q->whereIn('order_id', $orderIds())->orWhereNull('order_id')))
                ->get(['amount', 'created_at'])->each(fn ($e) => $add($key($e->created_at), 'revenue', -(int) $e->amount));
        }
        MerchantLedgerEntry::forCompany($company->id)->whereBetween('created_at', $range)->where('type', LedgerEntryType::LostCompensation->value)
            ->when($activity, fn ($q) => $q->whereIn('order_id', $orderIds()))
            ->get(['amount', 'created_at'])->each(fn ($e) => $add($key($e->created_at), 'costs', (int) $e->amount));

        // Forfaits mensuels et lots
        BillingPlan::forCompany($company->id)->overlapping($first->toDateString(), $now->toDateString())
            ->when($activity, fn ($q) => $q->where('activity', $activity))->get()
            ->each(function (BillingPlan $plan) use ($add, $months, $current, $today, &$full) {
                if ($plan->formula->isLot()) {
                    if ($plan->starts_on && $plan->starts_on->lessThanOrEqualTo($today)) {
                        $month = $plan->starts_on->format('Y-m');
                        $add($month, 'revenue', (int) $plan->lot_price);
                        $add($month, 'fixed_revenue', (int) $plan->lot_price);
                        $month === $current && $full['revenue'] += (int) $plan->lot_price;
                    }

                    return;
                }
                foreach (array_keys($months) as $month) {
                    $m = CarbonImmutable::parse($month.'-01');
                    $a = $m->max(CarbonImmutable::parse($plan->starts_on->toDateString()));
                    $end = $m->endOfMonth()->startOfDay()->min($plan->ends_on ? CarbonImmutable::parse($plan->ends_on->toDateString()) : $m->endOfMonth()->startOfDay());
                    // Mois en cours : jusqu'à aujourd'hui (le reste entre dans la projection)
                    $b = $end->min($today);
                    if ($b->greaterThanOrEqualTo($a)) {
                        $fee = (int) round($plan->monthly_fee * ($a->diffInDays($b) + 1) / $m->daysInMonth);
                        $add($month, 'revenue', $fee);
                        $add($month, 'fixed_revenue', $fee);
                    }
                    if ($month === $current && $end->greaterThanOrEqualTo($a)) {
                        $full['revenue'] += (int) round($plan->monthly_fee * ($a->diffInDays($end) + 1) / $m->daysInMonth);
                    }
                }
            });

        // Coûts : paie, frais de course, véhicules, frais fixes (toutes activités : pas de répartition ici)
        CourierEarning::forCompany($company->id)->whereBetween('created_at', $range)
            ->when($activity, fn ($q) => $q->whereIn('order_id', $orderIds()))
            ->get(['amount', 'created_at'])->each(fn ($e) => $add($key($e->created_at), 'costs', (int) $e->amount));
        OrderExpense::forCompany($company->id)->active()->where('billed_to', 'company')->whereBetween('created_at', $range)
            ->when($activity, fn ($q) => $q->whereIn('order_id', $orderIds()))
            ->get(['amount', 'created_at'])->each(fn ($e) => $add($key($e->created_at), 'costs', (int) $e->amount));
        if ($activity === null) {
            VehicleLog::forCompany($company->id)->where('paid_by', 'company')->whereBetween('done_on', [$first->toDateString(), $now->toDateString()])
                ->get(['amount', 'courier_share', 'done_on'])->each(fn ($l) => $add($l->done_on->format('Y-m'), 'costs', max(0, (int) $l->amount - (int) $l->courier_share)));
            $fixed = FixedCost::forCompany($company->id)->get();
            foreach (array_keys($months) as $month) {
                $m = CarbonImmutable::parse($month.'-01');
                $amount = (int) $fixed->sum(fn (FixedCost $c) => $c->amountBetween($m, $m->endOfMonth()->min($today)));
                $add($month, 'costs', $amount);
                $add($month, 'fixed_costs', $amount);
                if ($month === $current) {
                    $full['costs'] = (int) $fixed->sum(fn (FixedCost $c) => $c->amountBetween($m, $m->endOfMonth()));
                }
            }
        }

        return $full;
    }
}
