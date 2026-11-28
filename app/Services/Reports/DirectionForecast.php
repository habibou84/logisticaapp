<?php

namespace App\Services\Reports;

use App\Enums\Activity;
use App\Enums\BillingFormula;
use App\Models\BillingPlan;
use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * Statistiques › Aide à la décision : prévision du chiffre d'affaires et point de départ du simulateur « et si ».
 *
 * Prévision : la tendance des derniers mois terminés (droite des moindres carrés sur six mois au plus), prolongée
 * sur trois mois. La fourchette vient de l'écart habituel entre cette droite et les mois réels ; elle s'élargit avec
 * l'horizon. MAD et distribution : jamais sous les forfaits des contrats déjà signés pour le mois. C'est une
 * estimation simple, sans saisonnalité : il faut au moins trois mois terminés.
 *
 * Simulateur : les chiffres du dernier mois terminé (revenus, coûts, volumes) dont l'écran a besoin pour estimer
 * l'effet d'une décision ; le calcul du scénario se fait à l'écran.
 */
class DirectionForecast
{
    /** Mois terminés pris pour la tendance, au plus */
    private const FIT_MONTHS = 6;

    /** Mois terminés qu'il faut au moins */
    private const MIN_MONTHS = 3;

    /** Mois prévus */
    private const HORIZON = 3;

    /** Largeur de la fourchette : 1,28 écart type, soit environ 8 mois sur 10 dedans */
    private const BAND = 1.28;

    public function __construct(
        private readonly Performance $performance,
        private readonly Profitability $profitability,
        private readonly Statistics $statistics,
    ) {}

    /**
     * @param  list<Activity>  $activities  activités visibles (périmètre de la personne)
     * @return array<string, mixed>
     */
    public function forecast(Company $company, array $activities): array
    {
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($tz);
        $future = collect(range(1, self::HORIZON))->map(fn (int $h) => $now->startOfMonth()->addMonths($h));

        $rows = [];
        foreach ($activities as $activity) {
            $a = $activity->value;
            $months = $this->performance->build($company, true, $a)['months'];
            $current = array_pop($months);
            // L'historique commence au premier mois qui a du chiffre d'affaires
            $history = collect($months)->skipUntil(fn (array $m) => $m['revenue'] > 0)->values();
            $fit = $this->fit($history->pluck('revenue')->take(-self::FIT_MONTHS)->map(fn ($v) => (float) $v)->values()->all());
            $signed = $future->map(fn (CarbonImmutable $m) => $this->signed($company, $a, $m));

            $rows[] = [
                'value' => $a,
                'label' => $activity->label(),
                'history' => collect($months)->map(fn (array $m) => ['month' => $m['month'], 'label' => $m['label'], 'revenue' => (int) $m['revenue']])->all(),
                'current' => [
                    'month' => $current['month'], 'label' => $current['label'], 'revenue' => (int) $current['revenue'],
                    // Fin de mois au rythme des jours écoulés
                    'estimate' => (int) round($current['revenue'] * $now->daysInMonth / $now->day),
                ],
                'forecast' => $fit === null ? [] : $future->map(function (CarbonImmutable $m, int $i) use ($fit, $signed) {
                    // Le mois en cours est le premier pas après le dernier mois terminé
                    $step = $i + 2;
                    $value = max($signed[$i], $fit['intercept'] + $fit['slope'] * ($fit['n'] - 1 + $step));
                    $margin = self::BAND * $fit['deviation'] * sqrt($step);

                    return [
                        'month' => $m->format('Y-m'), 'label' => ucfirst($m->locale('fr')->isoFormat('MMM YYYY')),
                        'value' => (int) round($value), 'low' => (int) round(max($signed[$i], $value - $margin)), 'high' => (int) round($value + $margin),
                        'signed' => $signed[$i],
                    ];
                })->all(),
                'months_used' => $fit['n'] ?? $history->count(),
                'trend' => $fit === null ? null : (int) round($fit['slope']),
            ];
        }

        return [
            'activities' => $rows,
            'min_months' => self::MIN_MONTHS,
            'elapsed_days' => $now->day,
            'days_in_month' => $now->daysInMonth,
        ];
    }

    /**
     * Droite des moindres carrés (valeur = départ + pente × rang) et écart type des mois autour d'elle.
     *
     * @param  list<float>  $values
     * @return array{intercept: float, slope: float, deviation: float, n: int}|null
     */
    private function fit(array $values): ?array
    {
        $n = count($values);
        if ($n < self::MIN_MONTHS) {
            return null;
        }
        $meanX = ($n - 1) / 2;
        $meanY = array_sum($values) / $n;
        $sxx = 0.0;
        $sxy = 0.0;
        foreach ($values as $x => $y) {
            $sxx += ($x - $meanX) ** 2;
            $sxy += ($x - $meanX) * ($y - $meanY);
        }
        $slope = $sxx > 0 ? $sxy / $sxx : 0.0;
        $intercept = $meanY - $slope * $meanX;
        $residuals = 0.0;
        foreach ($values as $x => $y) {
            $residuals += ($y - ($intercept + $slope * $x)) ** 2;
        }

        return ['intercept' => $intercept, 'slope' => $slope, 'deviation' => sqrt($residuals / max(1, $n - 2)), 'n' => $n];
    }

    /**
     * Forfaits mensuels déjà signés pour un mois à venir (contrats en cours au milieu du mois, hors lots).
     */
    private function signed(Company $company, string $activity, CarbonImmutable $month): int
    {
        if ($activity === Activity::Ecommerce->value) {
            return 0;
        }
        $day = $month->startOfMonth()->addDays(14)->toDateString();

        return (int) BillingPlan::forCompany($company->id)->where('activity', $activity)
            ->where('formula', '!=', BillingFormula::DistributionLot->value)->overlapping($day, $day)->sum('monthly_fee');
    }

    /**
     * Point de départ du simulateur : le dernier mois terminé.
     *
     * @param  list<Activity>  $activities
     * @return array<string, mixed>
     */
    public function baseline(Company $company, array $activities): array
    {
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');
        $from = CarbonImmutable::now($tz)->startOfMonth()->subMonth()->startOfDay();
        $from = CarbonImmutable::parse($from->toDateString());
        $to = CarbonImmutable::parse($from->endOfMonth()->toDateString());
        $values = array_map(fn (Activity $a) => $a->value, $activities);
        $all = count($activities) === count(Activity::cases());

        $totals = [];
        foreach ($values as $a) {
            $totals[$a] = $this->profitability->build($company, $from, $to, $a);
        }
        $whole = $all ? $this->profitability->build($company, $from, $to)['totals'] : null;
        $out = [
            'month' => $from->format('Y-m'),
            'label' => ucfirst($from->locale('fr')->isoFormat('MMMM YYYY')),
            'revenue' => (int) array_sum(array_map(fn (string $a) => $totals[$a]['totals']['revenue_by_activity'][$a], $values)),
            'margin' => $whole['margin'] ?? (int) array_sum(array_map(fn (string $a) => $totals[$a]['totals']['margin'], $values)),
            'ecommerce' => null,
            'mad' => null,
        ];

        if (isset($totals['ecommerce'])) {
            $t = $totals['ecommerce']['totals'];
            $kpis = $this->statistics->for($company)->forActivity('ecommerce')->overview($from, $to, false)['kpis'];
            $loss = collect($totals['ecommerce']['zones'])->filter(fn ($z) => $z['margin'] < 0 && $z['delivered'] > 0);
            $out['ecommerce'] = [
                'revenue' => $t['revenue'],
                'delivered' => $t['delivered'],
                'returned' => $kpis['returned'],
                'revenue_per_delivery' => $t['revenue_per_delivery'],
                // Ce qu'une course livrée coûte directement (paie, véhicule, frais) : économisé si elle n'a pas lieu
                'direct_cost_per_delivery' => $t['delivered'] > 0 ? (int) round($t['direct_costs'] / $t['delivered']) : null,
                'loss_zones' => ['count' => $loss->count(), 'delivered' => (int) $loss->sum('delivered'), 'margin' => (int) $loss->sum('margin'), 'names' => $loss->sortBy('margin')->pluck('name')->take(5)->values()->all()],
            ];
        }
        if (isset($totals['mad'])) {
            $contracts = collect($totals['mad']['contracts'])->where('formula', BillingFormula::MadContract->value);
            $couriers = BillingPlan::forCompany($company->id)->where('formula', BillingFormula::MadContract->value)
                ->overlapping($from->toDateString(), $to->toDateString())->with('couriers:id')->get()->flatMap(fn (BillingPlan $p) => $p->couriers->modelKeys())->unique()->count();
            $out['mad'] = [
                'couriers' => $couriers,
                'revenue_per_courier' => $couriers > 0 ? (int) round($contracts->sum('revenue') / $couriers) : null,
                'cost_per_courier' => $couriers > 0 ? (int) round($contracts->sum('mad_cost') / $couriers) : null,
            ];
        }

        return $out;
    }
}
