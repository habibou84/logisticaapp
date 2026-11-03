<?php

namespace App\Services\Reports;

use App\Enums\AssignmentStatus;
use App\Enums\BillingFormula;
use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use App\Models\BillingPlan;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\FixedCost;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\OrderExpense;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\VehicleLog;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Rentabilité sur une période (Statistiques › Rentabilité) : ce que rapporte chaque client, chaque zone, chaque
 * livreur et chaque contrat, et ce qu'il coûte.
 *
 * Revenus :
 * - e-commerce : frais de livraison, de retour et de stockage inscrits au compte du client sur la période ;
 * - distribution, MAD : montant facturé des courses livrées sur la période, plus la part du forfait mensuel (au
 *   prorata des jours) ou le prix du lot (lot commencé sur la période).
 *
 * Coûts directs :
 * - paie des livreurs liée à une course (ramassage, livraison, retour, tentative) et frais de course à la charge de
 *   l'entreprise, compensations de colis perdus ;
 * - paie sans course (fixe, prime, journées MAD, indemnités) et coûts des véhicules payés par l'entreprise
 *   (carburant, entretien, réparations, papiers…) : répartis sur les missions faites par le livreur pendant la
 *   période ; un livreur mis à disposition (MAD) est compté entièrement sur ses contrats MAD.
 *
 * Frais fixes (loyer, équipe…) : répartis au nombre de courses livrées. Marge nette = revenus − coûts directs −
 * frais fixes. Les répartitions sont des estimations, signalées comme telles à l'écran.
 */
class Profitability
{
    /** Clients, zones et livreurs listés au plus */
    private const LIMIT = 200;

    public function __construct(private readonly StatsSettings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Company $company, CarbonImmutable $from, CarbonImmutable $to, ?string $activity = null): array
    {
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');
        $start = CarbonImmutable::parse($from->toDateString(), $tz)->startOfDay();
        $end = CarbonImmutable::parse($to->toDateString(), $tz)->endOfDay();
        $range = [$start->utc(), $end->utc()];
        $settings = $this->settings->for($company);
        $orders = fn () => Order::forCompany($company->id)->when($activity, fn ($q) => $q->where('activity', $activity));

        // Courses livrées sur la période
        $delivered = $orders()->where('status', OrderStatus::Delivered)->whereBetween('delivered_at', $range)
            ->get(['id', 'merchant_id', 'delivery_zone_id', 'activity', 'billing_plan_id', 'billing_amount', 'delivery_courier_id', 'delivery_fee', 'surcharges_total']);

        // Revenus et coûts rattachés à une course
        $ledger = MerchantLedgerEntry::forCompany($company->id)->whereBetween('created_at', $range)
            ->whereIn('type', [LedgerEntryType::DeliveryFee->value, LedgerEntryType::ReturnFee->value, LedgerEntryType::StorageFee->value, LedgerEntryType::LostCompensation->value])
            ->when($activity, fn ($q) => $q->where(fn ($q) => $q->whereIn('order_id', $orders()->select('id'))->orWhere(fn ($q) => $q->whereNull('order_id')->when($activity !== 'ecommerce', fn ($q) => $q->whereRaw('1 = 0')))))
            ->get(['merchant_id', 'order_id', 'type', 'amount']);
        $earnings = CourierEarning::forCompany($company->id)->whereBetween('created_at', $range)->get(['courier_id', 'order_id', 'type', 'amount']);
        $expenses = OrderExpense::forCompany($company->id)->active()->where('billed_to', 'company')->whereBetween('created_at', $range)->get(['order_id', 'amount']);

        // Missions faites sur la période : base de répartition des coûts du livreur
        $missions = OrderAssignment::query()->whereHas('order', fn ($q) => $q->withoutGlobalScopes()->where('company_id', $company->id))
            ->where('status', AssignmentStatus::Completed->value)->whereBetween('completed_at', $range)
            ->get(['courier_id', 'order_id', 'type', 'completed_at']);

        // Courses concernées (livrées, missions, coûts) : client, zone, activité
        $ids = $delivered->pluck('id')->merge($missions->pluck('order_id'))->merge($earnings->pluck('order_id'))->merge($expenses->pluck('order_id'))
            ->merge($ledger->pluck('order_id'))->filter()->unique()->values();
        $info = $orders()->whereIn('id', $ids)->get(['id', 'merchant_id', 'delivery_zone_id', 'activity', 'billing_plan_id'])->keyBy('id');

        $perOrder = [];  // order_id => [revenue, direct, overhead]
        $perMerchant = []; // merchant_id => [revenue, direct, overhead, courses]
        $bump = function (array &$bag, $key, string $field, float $amount) {
            $bag[$key] ??= ['revenue' => 0.0, 'direct' => 0.0, 'overhead' => 0.0, 'courses' => 0];
            $bag[$key][$field] += $amount;
        };
        $costs = ['courier_pay' => 0, 'courier_fixed' => 0, 'vehicles' => 0, 'expenses' => 0, 'losses' => 0];
        $revenueBy = ['ecommerce' => 0.0, 'distribution' => 0.0, 'mad' => 0.0];
        $activityOf = fn (Order $o) => $o->activity instanceof \BackedEnum ? $o->activity->value : (string) $o->activity;

        // 1. Revenus des courses
        foreach ($ledger as $e) {
            $amount = -(int) $e->amount;
            if ($e->type === LedgerEntryType::LostCompensation) {
                $costs['losses'] += (int) $e->amount;
                if ($e->order_id && isset($info[$e->order_id])) {
                    $bump($perOrder, $e->order_id, 'direct', (int) $e->amount);
                }
                $bump($perMerchant, $e->merchant_id, 'direct', (int) $e->amount);

                continue;
            }
            $revenueBy['ecommerce'] += $amount;
            if ($e->order_id && isset($info[$e->order_id])) {
                $bump($perOrder, $e->order_id, 'revenue', $amount);
            }
            $bump($perMerchant, $e->merchant_id, 'revenue', $amount);
        }
        foreach ($delivered as $o) {
            $bump($perMerchant, $o->merchant_id, 'courses', 1);
            if ($activityOf($o) === 'ecommerce') {
                continue;
            }
            $amount = (int) $o->billing_amount;
            $revenueBy[$activityOf($o)] += $amount;
            $bump($perOrder, $o->id, 'revenue', $amount);
            $bump($perMerchant, $o->merchant_id, 'revenue', $amount);
        }

        // 2. Forfaits et lots des contrats (distribution, MAD)
        $plans = BillingPlan::forCompany($company->id)->overlapping($start->toDateString(), $end->toDateString())
            ->when($activity, fn ($q) => $q->where('activity', $activity))
            ->with(['merchant:id,business_name', 'couriers:id'])->get();
        $planFee = [];
        foreach ($plans as $plan) {
            $fee = $plan->formula->isLot()
                ? ($plan->starts_on && $plan->starts_on->betweenIncluded($start->startOfDay(), $end) ? (int) $plan->lot_price : 0)
                : $this->prorata((int) $plan->monthly_fee, $plan, $start, $end);
            $planFee[$plan->id] = $fee;
            $revenueBy[$plan->activity->value] += $fee;
            $bump($perMerchant, $plan->merchant_id, 'revenue', $fee);
        }

        // 3. Coûts directs des courses
        foreach ($earnings->whereNotNull('order_id') as $e) {
            if (! isset($info[$e->order_id])) {
                continue;
            }
            $costs['courier_pay'] += (int) $e->amount;
            $bump($perOrder, $e->order_id, 'direct', (int) $e->amount);
            $bump($perMerchant, $info[$e->order_id]->merchant_id, 'direct', (int) $e->amount);
        }
        foreach ($expenses as $e) {
            if (! isset($info[$e->order_id])) {
                continue;
            }
            $costs['expenses'] += (int) $e->amount;
            $bump($perOrder, $e->order_id, 'direct', (int) $e->amount);
            $bump($perMerchant, $info[$e->order_id]->merchant_id, 'direct', (int) $e->amount);
        }

        // 4. Coûts du livreur sans course (paie fixe, primes, MAD, indemnités) et de son véhicule
        $courierFixed = $earnings->whereNull('order_id')->groupBy('courier_id')->map(fn (Collection $c) => (int) $c->sum('amount'));
        $vehicleCosts = $this->vehicleCosts($company, $start, $end);
        $madPlans = []; // courier_id => list<plan>
        foreach ($plans->filter(fn (BillingPlan $p) => $p->formula === BillingFormula::MadContract) as $plan) {
            foreach ($plan->couriers as $c) {
                $madPlans[$c->id][] = $plan;
            }
        }
        $byCourierMissions = $missions->groupBy('courier_id');
        $allMissions = OrderAssignment::query()->whereHas('order', fn ($q) => $q->withoutGlobalScopes()->where('company_id', $company->id))
            ->where('status', AssignmentStatus::Completed->value)->whereBetween('completed_at', $range)
            ->selectRaw('courier_id, COUNT(*) AS n')->groupBy('courier_id')->pluck('n', 'courier_id');
        $planCost = [];
        $courierIds = $courierFixed->keys()->merge(array_keys($vehicleCosts))->unique();
        foreach ($courierIds as $courierId) {
            $fixed = (int) ($courierFixed[$courierId] ?? 0);
            $vehicle = (int) ($vehicleCosts[$courierId] ?? 0);
            $total = $fixed + $vehicle;
            if ($total === 0) {
                continue;
            }
            // Livreur mis à disposition : sur ses contrats MAD (parts égales)
            if (isset($madPlans[$courierId])) {
                $share = $total / count($madPlans[$courierId]);
                foreach ($madPlans[$courierId] as $plan) {
                    $planCost[$plan->id] = ($planCost[$plan->id] ?? 0) + $share;
                    $bump($perMerchant, $plan->merchant_id, 'overhead', $share);
                }
                $costs['courier_fixed'] += $fixed;
                $costs['vehicles'] += $vehicle;

                continue;
            }
            // Sinon : réparti sur ses missions (seulement la part des courses regardées)
            $all = (int) ($allMissions[$courierId] ?? 0);
            $mine = $byCourierMissions->get($courierId, collect())->filter(fn ($m) => isset($info[$m->order_id]));
            if ($all === 0) {
                if ($activity === null) {
                    $costs['courier_fixed'] += $fixed;
                    $costs['vehicles'] += $vehicle;
                }

                continue;
            }
            $per = $total / $all;
            $costs['courier_fixed'] += (int) round($fixed * $mine->count() / $all);
            $costs['vehicles'] += (int) round($vehicle * $mine->count() / $all);
            foreach ($mine as $m) {
                $bump($perOrder, $m->order_id, 'overhead', $per);
                $bump($perMerchant, $info[$m->order_id]->merchant_id, 'overhead', $per);
            }
        }

        // 5. Frais fixes : la part des courses regardées, répartie au nombre de courses livrées
        $fixedAll = (int) FixedCost::forCompany($company->id)->get()->sum(fn (FixedCost $c) => $c->amountBetween($start, $end));
        $deliveredAll = $activity ? Order::forCompany($company->id)->where('status', OrderStatus::Delivered)->whereBetween('delivered_at', $range)->count() : $delivered->count();
        $fixed = $deliveredAll > 0 ? (int) round($fixedAll * $delivered->count() / $deliveredAll) : ($activity ? 0 : $fixedAll);
        $fixedPerCourse = $delivered->count() > 0 ? $fixed / $delivered->count() : 0;

        $revenue = (int) round(array_sum($revenueBy));
        $direct = array_sum($costs);
        $count = $delivered->count();
        $days = (int) $start->diffInDays($end->startOfDay()) + 1;
        $courierDays = $missions->map(fn ($m) => $m->courier_id.'|'.CarbonImmutable::parse($m->completed_at)->setTimezone($tz)->toDateString())->unique()->count();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'estimated' => true,
            'totals' => [
                'revenue' => $revenue,
                'revenue_by_activity' => array_map(fn ($v) => (int) round($v), $revenueBy),
                'costs' => $costs,
                'direct_costs' => $direct,
                'contribution' => $revenue - $direct,
                'fixed_costs' => $fixed,
                'margin' => $revenue - $direct - $fixed,
                'margin_rate' => $revenue > 0 ? round(($revenue - $direct - $fixed) / $revenue * 100, 1) : null,
                'delivered' => $count,
                'cost_per_delivery' => $count ? (int) round(($direct + $fixed) / $count) : null,
                'revenue_per_delivery' => $count ? (int) round($revenue / $count) : null,
                'margin_per_delivery' => $count ? (int) round(($revenue - $direct - $fixed) / $count) : null,
                'courier_days' => $courierDays,
                'deliveries_per_courier_day' => $courierDays ? round($missions->filter(fn ($m) => ($m->type->value ?? $m->type) === 'delivery')->count() / $courierDays, 1) : null,
                'days' => $days,
            ],
            'clients' => $this->clients($perMerchant, $fixedPerCourse, $settings),
            'zones' => $this->zones($delivered, $perOrder, $fixedPerCourse),
            'couriers' => $this->couriers($company, $missions, $earnings, $vehicleCosts, $perOrder, $info, $delivered, $tz),
            'contracts' => $this->contracts($company, $plans, $planFee, $planCost, $start, $end, $settings),
            'settings' => collect($settings)->only(['renegotiate_margin', 'forfait_high', 'forfait_low'])->all(),
        ];
    }

    /**
     * Forfait mensuel au prorata des jours du contrat compris dans la période.
     */
    private function prorata(int $monthly, BillingPlan $plan, CarbonImmutable $start, CarbonImmutable $end): int
    {
        if ($monthly <= 0) {
            return 0;
        }
        $from = $start->startOfDay()->max(CarbonImmutable::parse($plan->starts_on->toDateString()));
        $to = $end->startOfDay()->min($plan->ends_on ? CarbonImmutable::parse($plan->ends_on->toDateString()) : $end->startOfDay());
        $total = 0.0;
        for ($m = $from->startOfMonth(); $m->lessThanOrEqualTo($to); $m = $m->addMonth()) {
            $a = $m->max($from);
            $b = $m->endOfMonth()->startOfDay()->min($to);
            if ($b->greaterThanOrEqualTo($a)) {
                $total += $monthly * ($a->diffInDays($b) + 1) / $m->daysInMonth;
            }
        }

        return (int) round($total);
    }

    /**
     * Coûts des véhicules payés par l'entreprise sur la période, par livreur (celui du relevé, sinon le livreur
     * actuel du véhicule).
     *
     * @return array<int, int>
     */
    private function vehicleCosts(Company $company, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $out = [];
        $vehicles = Vehicle::forCompany($company->id)->pluck('courier_id', 'id');
        VehicleLog::forCompany($company->id)->where('paid_by', 'company')->whereBetween('done_on', [$start->toDateString(), $end->toDateString()])
            ->get(['vehicle_id', 'courier_id', 'amount', 'courier_share'])
            ->each(function ($log) use (&$out, $vehicles) {
                $courier = $log->courier_id ?? $vehicles[$log->vehicle_id] ?? null;
                if ($courier) {
                    $out[$courier] = ($out[$courier] ?? 0) + max(0, (int) $log->amount - (int) $log->courier_share);
                }
            });
        VehicleDocument::withoutGlobalScopes()->whereIn('vehicle_id', $vehicles->keys())->whereBetween('starts_on', [$start->toDateString(), $end->toDateString()])
            ->get(['vehicle_id', 'cost'])
            ->each(function ($doc) use (&$out, $vehicles) {
                $courier = $vehicles[$doc->vehicle_id] ?? null;
                if ($courier && $doc->cost) {
                    $out[$courier] = ($out[$courier] ?? 0) + (int) $doc->cost;
                }
            });

        return $out;
    }

    /**
     * @param  array<int, array{revenue: float, direct: float, overhead: float, courses: int}>  $perMerchant
     * @param  array<string, mixed>  $settings
     * @return list<array<string, mixed>>
     */
    private function clients(array $perMerchant, float $fixedPerCourse, array $settings): array
    {
        $merchants = Merchant::withoutGlobalScopes()->whereIn('id', array_keys($perMerchant))->get(['id', 'business_name'])->keyBy('id');

        return collect($perMerchant)->map(function (array $m, $id) use ($merchants, $fixedPerCourse, $settings) {
            $fixed = $m['courses'] * $fixedPerCourse;
            $costs = $m['direct'] + $m['overhead'] + $fixed;
            $margin = $m['revenue'] - $costs;
            $rate = $m['revenue'] > 0 ? round($margin / $m['revenue'] * 100, 1) : null;

            return [
                'id' => (int) $id,
                'name' => $merchants[$id]->business_name ?? 'Client',
                'courses' => $m['courses'],
                'revenue' => (int) round($m['revenue']),
                'direct_costs' => (int) round($m['direct'] + $m['overhead']),
                'fixed_share' => (int) round($fixed),
                'margin' => (int) round($margin),
                'margin_rate' => $rate,
                'margin_per_course' => $m['courses'] ? (int) round($margin / $m['courses']) : null,
                'verdict' => $margin < 0 ? 'loss' : ($rate !== null && $rate < $settings['renegotiate_margin'] ? 'renegotiate' : 'profitable'),
            ];
        })->sortBy('margin')->take(self::LIMIT)->values()->all();
    }

    /**
     * @param  Collection<int, Order>  $delivered
     * @param  array<int, array{revenue: float, direct: float, overhead: float}>  $perOrder
     * @return list<array<string, mixed>>
     */
    private function zones(Collection $delivered, array $perOrder, float $fixedPerCourse): array
    {
        $names = Zone::withoutGlobalScopes()->whereIn('id', $delivered->pluck('delivery_zone_id')->filter()->unique())->pluck('name', 'id');

        return $delivered->groupBy('delivery_zone_id')->map(function (Collection $list, $zoneId) use ($names, $perOrder, $fixedPerCourse) {
            $revenue = $list->sum(fn (Order $o) => $perOrder[$o->id]['revenue'] ?? 0);
            $costs = $list->sum(fn (Order $o) => ($perOrder[$o->id]['direct'] ?? 0) + ($perOrder[$o->id]['overhead'] ?? 0)) + $list->count() * $fixedPerCourse;
            $n = $list->count();

            return [
                'id' => $zoneId ? (int) $zoneId : null,
                'name' => $names[$zoneId] ?? 'Sans zone',
                'delivered' => $n,
                'revenue' => (int) round($revenue),
                'costs' => (int) round($costs),
                'margin' => (int) round($revenue - $costs),
                'margin_rate' => $revenue > 0 ? round(($revenue - $costs) / $revenue * 100, 1) : null,
                'average_fee' => $n ? (int) round($revenue / $n) : null,
                'cost_per_delivery' => $n ? (int) round($costs / $n) : null,
            ];
        })->sortBy('margin')->take(self::LIMIT)->values()->all();
    }

    /**
     * Livreurs : missions, livraisons par jour travaillé, paie, véhicule, coût par mission, revenus des courses
     * livrées et marge (revenus − paie − véhicule).
     *
     * @param  Collection<int, OrderAssignment>  $missions
     * @param  Collection<int, CourierEarning>  $earnings
     * @param  array<int, int>  $vehicleCosts
     * @param  array<int, array{revenue: float, direct: float, overhead: float}>  $perOrder
     * @param  Collection<int, Order>  $info
     * @param  Collection<int, Order>  $delivered
     * @return list<array<string, mixed>>
     */
    private function couriers(Company $company, Collection $missions, Collection $earnings, array $vehicleCosts, array $perOrder, Collection $info, Collection $delivered, string $tz): array
    {
        $mine = $missions->filter(fn ($m) => isset($info[$m->order_id]));
        $ids = $mine->pluck('courier_id')->merge($delivered->pluck('delivery_courier_id'))->filter()->unique();
        $names = Courier::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('id', $ids)->with('user:id,name')->get()->mapWithKeys(fn (Courier $c) => [$c->id => $c->user?->name]);
        $byCourier = $mine->groupBy('courier_id');
        $deliveredBy = $delivered->groupBy('delivery_courier_id');
        $pay = $earnings->groupBy('courier_id')->map(fn (Collection $c) => (int) $c->sum('amount'));

        return $ids->map(function ($id) use ($names, $byCourier, $deliveredBy, $pay, $vehicleCosts, $perOrder, $tz) {
            $list = $byCourier->get($id, collect());
            $days = $list->map(fn ($m) => CarbonImmutable::parse($m->completed_at)->setTimezone($tz)->toDateString())->unique()->count();
            $deliveries = $list->filter(fn ($m) => ($m->type->value ?? $m->type) === 'delivery')->count();
            $revenue = $deliveredBy->get($id, collect())->sum(fn (Order $o) => $perOrder[$o->id]['revenue'] ?? 0);
            $cost = (int) ($pay[$id] ?? 0) + (int) ($vehicleCosts[$id] ?? 0);

            return [
                'id' => (int) $id,
                'name' => $names[$id] ?? 'Livreur',
                'missions' => $list->count(),
                'deliveries' => $deliveries,
                'days_worked' => $days,
                'per_day' => $days ? round($deliveries / $days, 1) : null,
                'pay' => (int) ($pay[$id] ?? 0),
                'vehicle' => (int) ($vehicleCosts[$id] ?? 0),
                'cost_per_mission' => $list->count() ? (int) round($cost / $list->count()) : null,
                'revenue' => (int) round($revenue),
                'margin' => (int) round($revenue - $cost),
            ];
        })->sortByDesc('deliveries')->take(self::LIMIT)->values()->all();
    }

    /**
     * Contrats en cours : utilisation du forfait par mois (courses comptées / incluses) et verdict ; MAD : prix du
     * contrat comparé au coût des livreurs mis à disposition.
     *
     * @param  Collection<int, BillingPlan>  $plans
     * @param  array<int, int>  $planFee
     * @param  array<int, float>  $planCost
     * @param  array<string, mixed>  $settings
     * @return list<array<string, mixed>>
     */
    private function contracts(Company $company, Collection $plans, array $planFee, array $planCost, CarbonImmutable $start, CarbonImmutable $end, array $settings): array
    {
        $months = max(1.0, round(($start->diffInDays($end) + 1) / 30.4, 2));
        $used = Order::forCompany($company->id)->whereIn('billing_plan_id', $plans->modelKeys())->whereBetween('created_at', [$start->utc(), $end->utc()])
            ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Rejected->value])
            ->selectRaw('billing_plan_id, COUNT(*) AS n, SUM(billing_amount) AS billed')->groupBy('billing_plan_id')->get()->keyBy('billing_plan_id');

        return $plans->map(function (BillingPlan $plan) use ($used, $months, $planFee, $planCost, $settings) {
            $count = (int) ($used[$plan->id]->n ?? 0);
            $included = $plan->formula->isLot() ? (int) $plan->lot_quantity : (int) $plan->included_quantity;
            $perMonth = $plan->formula->isLot() ? $count : round($count / $months, 1);
            $usage = $included > 0 ? (int) round($perMonth / $included * 100) : null;
            $revenue = ($planFee[$plan->id] ?? 0) + (int) ($used[$plan->id]->billed ?? 0);
            $cost = (int) round($planCost[$plan->id] ?? 0);

            return [
                'id' => $plan->id,
                'merchant' => $plan->merchant?->business_name,
                'merchant_id' => $plan->merchant_id,
                'formula' => $plan->formula->value,
                'label' => $plan->formula->isLot() ? $plan->lotName() : $plan->formula->label(),
                'monthly_fee' => $plan->formula->isLot() ? null : (int) $plan->monthly_fee,
                'included' => $included ?: null,
                'used' => $count,
                'used_per_month' => $perMonth,
                'usage' => $usage,
                'verdict' => $usage === null ? null : ($usage > $settings['forfait_high'] ? 'raise' : ($usage < $settings['forfait_low'] ? 'underused' : 'ok')),
                'revenue' => $revenue,
                // MAD : coût des livreurs mis à disposition (paie sans course et véhicule)
                'mad_cost' => $plan->formula === BillingFormula::MadContract ? $cost : null,
                'mad_margin' => $plan->formula === BillingFormula::MadContract ? $revenue - $cost : null,
            ];
        })->sortByDesc('usage')->values()->all();
    }
}
