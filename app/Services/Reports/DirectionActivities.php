<?php

namespace App\Services\Reports;

use App\Enums\BillingFormula;
use App\Enums\OrderStatus;
use App\Models\BillingPlan;
use App\Models\Company;
use App\Models\CourierShift;
use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * Statistiques de direction › écrans par activité : chaque activité avec ses propres indicateurs.
 * - e-commerce : parcours des colis, motifs d'échec, marge par zone, argent en circulation, clients principaux ;
 * - MAD : revenu des forfaits, consommation, présence et retards par contrat, marge, échéances ;
 * - distribution : volumes par type d'envoi, délai tenu par délai vendu, forfaits, lots, paiement des factures.
 *
 * Les montants et les taux viennent des calculs de Statistiques (rentabilité, qualité, clients) ; cet écran les
 * rassemble par activité et y ajoute les points « À décider » de l'activité.
 */
class DirectionActivities
{
    /** Clients listés (les plus gros chiffres d'affaires) */
    private const TOP = 15;

    public function __construct(
        private readonly DirectionBoard $board,
        private readonly Profitability $profitability,
        private readonly Performance $performance,
        private readonly ServiceQuality $quality,
        private readonly ClientInsights $insights,
        private readonly Statistics $statistics,
    ) {}

    /** @return array<string, mixed> */
    public function ecommerce(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $a = 'ecommerce';
        [$prevFrom, $prevTo] = Statistics::previous($from, $to);
        $stats = $this->statistics->for($company)->forActivity($a);
        $overview = $stats->overview($from, $to, true);
        $deliveries = $stats->deliveries($from, $to);
        $profit = $this->profitability->build($company, $from, $to, $a);
        $before = $this->profitability->build($company, $prevFrom, $prevTo, $a)['totals'];
        $k = $overview['kpis'];
        $p = $overview['previous_kpis'];
        $returnRate = fn (array $kpis) => $kpis['delivered'] + $kpis['returned'] > 0 ? round($kpis['returned'] / ($kpis['delivered'] + $kpis['returned']) * 100, 1) : null;
        $pickedUp = Order::forCompany($company->id)->where('activity', $a)->whereBetween('picked_up_at', $this->range($company, $from, $to))->count();

        // Clients : rentabilité et réussite réunies, les plus gros chiffres d'affaires d'abord
        $success = collect($stats->merchants($from, $to, false)['merchants'] ?? [])->keyBy('id');
        $clients = collect($profit['clients'])->filter(fn ($c) => $c['revenue'] > 0)->sortByDesc('revenue')->values();
        $revenue = (int) $clients->sum('revenue');

        return [
            'activity' => $a,
            ...$this->periods($from, $to, $prevFrom, $prevTo),
            'kpis' => [
                'delivered' => $k['delivered'], 'previous_delivered' => $p['delivered'],
                'success_rate' => $k['success_rate'], 'previous_success_rate' => $p['success_rate'],
                'return_rate' => $returnRate($k), 'previous_return_rate' => $returnRate($p),
                'revenue' => $profit['totals']['revenue'], 'previous_revenue' => $before['revenue'],
                'revenue_per_delivery' => $profit['totals']['revenue_per_delivery'], 'previous_revenue_per_delivery' => $before['revenue_per_delivery'],
                'margin_per_delivery' => $profit['totals']['margin_per_delivery'], 'previous_margin_per_delivery' => $before['margin_per_delivery'],
                'collected' => $k['collected'], 'previous_collected' => $p['collected'],
                'first_attempt_rate' => $deliveries['first_attempt_rate'],
            ],
            // Argent en circulation à cet instant (toutes activités : l'encaissement à la livraison est surtout e-commerce)
            'cash' => $this->statistics->for($company)->finances($from, $to)['circulation'],
            'funnel' => [
                ['key' => 'created', 'label' => 'Créées', 'count' => $k['created']],
                ['key' => 'picked_up', 'label' => 'Ramassées', 'count' => $pickedUp],
                ['key' => 'first_attempt', 'label' => 'Livrées au 1er passage', 'count' => (int) round($deliveries['delivered'] * ($deliveries['first_attempt_rate'] ?? 0) / 100)],
                ['key' => 'delivered', 'label' => 'Livrées au total', 'count' => $k['delivered']],
                ['key' => 'returned', 'label' => 'Retournées', 'count' => $k['returned']],
            ],
            'failed_attempts' => $deliveries['failed_attempts'],
            'failure_reasons' => collect($deliveries['failure_reasons'])->take(8)->values()->all(),
            'zones' => collect($profit['zones'])->filter(fn ($z) => $z['delivered'] > 0)
                ->map(fn ($z) => [...collect($z)->only(['id', 'name', 'delivered', 'revenue', 'margin'])->all(), 'margin_per_delivery' => (int) round($z['margin'] / $z['delivered'])])
                ->sortByDesc('margin_per_delivery')->values()->all(),
            'clients' => $clients->take(self::TOP)->map(function ($c) use ($success, $revenue) {
                $s = $success[$c['id']] ?? null;
                $finished = $s ? $s['delivered'] + $s['returned'] : 0;

                return [
                    ...collect($c)->only(['id', 'name', 'courses', 'revenue', 'margin', 'margin_per_course', 'verdict'])->all(),
                    'share' => $revenue > 0 ? round($c['revenue'] / $revenue * 100, 1) : null,
                    'success_rate' => $s['success_rate'] ?? null,
                    'return_rate' => $finished > 0 ? round($s['returned'] / $finished * 100, 1) : null,
                ];
            })->all(),
            'clients_total' => $clients->count(),
            'months' => $this->months($company, $a),
            'decisions' => $this->board->decisions($company, [$a], [$a => $profit], ['lots' => [], 'mad' => []], []),
        ];
    }

    /** @return array<string, mixed> */
    public function mad(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $a = 'mad';
        [$prevFrom, $prevTo] = Statistics::previous($from, $to);
        $now = CarbonImmutable::now($company->timezone ?: (string) config('app.timezone', 'UTC'));
        $profit = $this->profitability->build($company, $from, $to, $a);
        $before = $this->profitability->build($company, $prevFrom, $prevTo, $a)['totals'];
        $quality = $this->quality->build($company, $from, $to, $a);
        $payments = $this->insights->payments($company, $now, $a);
        $presence = collect($quality['mad'])->keyBy('id');
        $plans = BillingPlan::forCompany($company->id)->where('formula', BillingFormula::MadContract->value)
            ->overlapping($from->toDateString(), $to->toDateString())->with('couriers:id')->get()->keyBy('id');
        // Retard moyen au pointage, par contrat (minutes)
        $late = CourierShift::forCompany($company->id)->whereIn('billing_plan_id', $plans->keys())
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('billing_plan_id, AVG(late_minutes) AS late, COUNT(*) AS n')->groupBy('billing_plan_id')->get()->keyBy('billing_plan_id');

        $contracts = collect($profit['contracts'])->where('formula', BillingFormula::MadContract->value)->map(function ($c) use ($presence, $plans, $late, $now) {
            $plan = $plans[$c['id']] ?? null;
            $days = $presence[$c['id']] ?? null;
            $ends = $plan?->ends_on;

            return [
                ...collect($c)->only(['id', 'merchant', 'merchant_id', 'monthly_fee', 'included', 'used', 'used_per_month', 'usage', 'verdict', 'revenue'])->all(),
                'couriers' => $plan?->couriers->count() ?? 0,
                'courier_names' => $days['couriers'] ?? [],
                'planned_days' => $days['planned_days'] ?? null,
                'done_days' => $days['done_days'] ?? null,
                'presence' => $days['rate'] ?? null,
                'late_minutes' => isset($late[$c['id']]) ? (int) round((float) $late[$c['id']]->late) : null,
                'cost' => $c['mad_cost'],
                'margin' => $c['mad_margin'],
                'margin_rate' => $c['revenue'] > 0 && $c['mad_margin'] !== null ? round($c['mad_margin'] / $c['revenue'] * 100, 1) : null,
                'ends_on' => $ends?->toDateString(),
                'renew' => $ends !== null && $ends->greaterThanOrEqualTo($now->startOfDay()) && $ends->lessThanOrEqualTo($now->addDays(DirectionBoard::RENEWAL_DAYS)),
            ];
        })->values();

        $couriers = $plans->flatMap(fn (BillingPlan $p) => $p->couriers->modelKeys())->unique()->count();
        $planned = (int) $contracts->sum('planned_days');
        $shifts = (int) $late->sum('n');

        return [
            'activity' => $a,
            ...$this->periods($from, $to, $prevFrom, $prevTo),
            'kpis' => [
                'revenue' => $profit['totals']['revenue'], 'previous_revenue' => $before['revenue'],
                'margin' => $profit['totals']['margin'], 'previous_margin' => $before['margin'],
                'margin_rate' => $profit['totals']['margin_rate'],
                // Revenu récurrent : somme des forfaits mensuels des contrats en cours
                'monthly_fees' => (int) $contracts->sum('monthly_fee'),
                'contracts' => $contracts->count(),
                'couriers' => $couriers,
                'margin_per_courier' => $couriers > 0 ? (int) round($contracts->sum('margin') / $couriers) : null,
                // Courses au-delà du forfait, par mois
                'over_forfait' => (int) round($contracts->sum(fn ($c) => $c['included'] ? max(0, $c['used_per_month'] - $c['included']) : 0)),
                'presence' => $planned > 0 ? round($contracts->sum('done_days') / $planned * 100, 1) : null,
                'late_minutes' => $shifts > 0 ? (int) round($late->sum(fn ($l) => (float) $l->late * $l->n) / $shifts) : null,
                'to_renew' => $contracts->where('renew', true)->count(),
                'overdue' => $this->board->overdue($payments),
                'outstanding' => (int) $payments['outstanding'],
            ],
            'contracts' => $contracts->all(),
            'thresholds' => collect($profit['settings'])->only(['forfait_high', 'forfait_low'])->all(),
            'months' => $this->months($company, $a),
            'decisions' => $this->board->decisions($company, [$a], [$a => $profit], $quality, [$a => $payments]),
        ];
    }

    /** @return array<string, mixed> */
    public function distribution(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $a = 'distribution';
        [$prevFrom, $prevTo] = Statistics::previous($from, $to);
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($tz);
        $stats = $this->statistics->for($company)->forActivity($a);
        $overview = $stats->overview($from, $to, false);
        $shipments = $stats->shipments($from, $to);
        $profit = $this->profitability->build($company, $from, $to, $a);
        $before = $this->profitability->build($company, $prevFrom, $prevTo, $a)['totals'];
        $quality = $this->quality->build($company, $from, $to, $a);
        $earlier = $this->quality->build($company, $prevFrom, $prevTo, $a);
        $payments = $this->insights->payments($company, $now, $a);
        $delivered = $overview['kpis']['delivered'];
        $interior = (int) (collect($shipments['destinations'])->firstWhere('value', 'interior')['count'] ?? 0);

        // Envois livrés par semaine (du lundi) et par type
        $types = collect($shipments['types'])->pluck('label', 'value');
        $weeks = [];
        Order::forCompany($company->id)->where('activity', $a)->where('status', OrderStatus::Delivered)
            ->whereBetween('delivered_at', $this->range($company, $from, $to))->get(['shipment_type', 'shipment_quantity', 'delivered_at'])
            ->each(function (Order $o) use (&$weeks, $tz, $types) {
                $week = CarbonImmutable::parse($o->delivered_at)->setTimezone($tz)->startOfWeek()->toDateString();
                $type = $o->shipment_type?->value ?? (string) $o->shipment_type;
                $weeks[$week] ??= ['week' => $week, 'counts' => $types->map(fn () => 0)->all()];
                if (isset($weeks[$week]['counts'][$type])) {
                    $weeks[$week]['counts'][$type] += max(1, (int) $o->shipment_quantity);
                }
            });
        ksort($weeks);

        $contracts = collect($profit['contracts']);
        $lots = collect($quality['lots']);

        return [
            'activity' => $a,
            ...$this->periods($from, $to, $prevFrom, $prevTo),
            'kpis' => [
                'delivered' => $delivered, 'previous_delivered' => $overview['previous_kpis']['delivered'],
                'revenue' => $profit['totals']['revenue'], 'previous_revenue' => $before['revenue'],
                'margin' => $profit['totals']['margin'], 'previous_margin' => $before['margin'],
                'margin_rate' => $profit['totals']['margin_rate'],
                'on_time_rate' => $quality['delays']['rate'], 'previous_on_time_rate' => $earlier['delays']['rate'],
                'revenue_per_delivery' => $profit['totals']['revenue_per_delivery'], 'previous_revenue_per_delivery' => $before['revenue_per_delivery'],
                'interior_share' => $shipments['total'] > 0 ? round($interior / $shipments['total'] * 100, 1) : null,
                'lots_open' => $lots->whereIn('status', ['in_progress', 'late'])->filter(fn ($l) => $l['delivered'] < $l['quantity'])->count(),
                'payment_days' => $payments['average_days'],
                'overdue' => $this->board->overdue($payments),
                'outstanding' => (int) $payments['outstanding'],
            ],
            'types' => $shipments['types'],
            'destinations' => $shipments['destinations'],
            'weeks' => array_values($weeks),
            'delays' => collect($quality['delays']['by_delay'])->sortBy(fn ($r) => $r['key'] === 'interior' ? 'zz' : $r['key'])->values()->all(),
            'late_clients' => collect($quality['delays']['by_client'])->filter(fn ($r) => $r['late'] > 0)->sortByDesc('late')->take(8)->values()->all(),
            'contracts' => $contracts->where('formula', '!=', BillingFormula::DistributionLot->value)
                ->map(fn ($c) => collect($c)->only(['id', 'merchant', 'merchant_id', 'label', 'monthly_fee', 'included', 'used', 'used_per_month', 'usage', 'verdict', 'revenue'])->all())->values()->all(),
            'lots' => $lots->all(),
            'thresholds' => collect($profit['settings'])->only(['forfait_high', 'forfait_low'])->all(),
            'payments' => ['buckets' => $payments['buckets'], 'clients' => collect($payments['clients'])->filter(fn ($c) => $c['balance'] > 0)->take(8)->values()->all()],
            'months' => $this->months($company, $a),
            'decisions' => $this->board->decisions($company, [$a], [$a => $profit], $quality, [$a => $payments]),
        ];
    }

    /**
     * Douze derniers mois de l'activité : courses livrées, taux de réussite, chiffre d'affaires, marge.
     *
     * @return list<array<string, mixed>>
     */
    private function months(Company $company, string $activity): array
    {
        return collect(array_slice($this->performance->build($company, true, $activity)['months'], -12))
            ->map(fn (array $m) => collect($m)->only(['month', 'label', 'delivered', 'success_rate', 'revenue', 'margin'])->all())->all();
    }

    /** @return array<string, array{from: string, to: string}> */
    private function periods(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $prevFrom, CarbonImmutable $prevTo): array
    {
        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'previous_period' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
        ];
    }

    /**
     * Période en UTC (jours du fuseau de l'entreprise).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');

        return [CarbonImmutable::parse($from->toDateString(), $tz)->startOfDay()->utc(), CarbonImmutable::parse($to->toDateString(), $tz)->endOfDay()->utc()];
    }
}
