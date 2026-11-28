<?php

namespace App\Services\Reports;

use App\Enums\Activity;
use App\Enums\BillingFormula;
use App\Models\BillingPlan;
use App\Models\Company;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Statistiques de direction › Vue d'ensemble : les activités de l'entreprise côte à côte (chiffre d'affaires, marge nette,
 * courses livrées, qualité, factures en retard) comparées à la période précédente, le chiffre d'affaires des douze
 * derniers mois par activité, et les points « À décider » : écarts chiffrés, classés par gravité, avec le lien vers
 * l'écran où agir.
 *
 * Les montants viennent des calculs de Statistiques › Rentabilité (mêmes règles, mêmes estimations) ; les seuils sont
 * ceux des réglages des statistiques (forfait dépassé ou sous-utilisé, baisse d'un client, volume minimal).
 */
class DirectionBoard
{
    /** Contrat à renouveler : échéance dans ce nombre de jours */
    public const RENEWAL_DAYS = 60;

    /** Présence d'un contrat MAD signalée sous ce taux */
    public const MAD_PRESENCE_MIN = 90;

    /** Hausse du chiffre d'affaires d'une activité signalée comme bonne nouvelle (en %) */
    public const GROWTH_GOOD = 10;

    private const LEVELS = ['critical' => 0, 'warning' => 1, 'info' => 2, 'good' => 3];

    public function __construct(
        private readonly Profitability $profitability,
        private readonly Performance $performance,
        private readonly ServiceQuality $quality,
        private readonly ClientInsights $insights,
        private readonly Statistics $statistics,
        private readonly StatsSettings $settings,
    ) {}

    /**
     * @param  list<Activity>  $activities  activités visibles (périmètre de la personne)
     * @return array<string, mixed>
     */
    public function overview(Company $company, CarbonImmutable $from, CarbonImmutable $to, array $activities): array
    {
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($tz);
        [$prevFrom, $prevTo] = Statistics::previous($from, $to);
        $settings = $this->settings->for($company);
        $all = count($activities) === count(Activity::cases());
        $values = array_map(fn (Activity $a) => $a->value, $activities);

        $current = [];
        $previous = [];
        foreach ($values as $a) {
            $current[$a] = $this->profitability->build($company, $from, $to, $a);
            $previous[$a] = $this->profitability->build($company, $prevFrom, $prevTo, $a)['totals'];
        }
        $quality = $this->quality->build($company, $from, $to);
        $payments = [];
        foreach (array_intersect($values, ['distribution', 'mad']) as $a) {
            $payments[$a] = $this->insights->payments($company, $now, $a);
        }
        $months = [];
        foreach ($values as $a) {
            $months[$a] = array_slice($this->performance->build($company, true, $a)['months'], -12);
        }

        $rows = [];
        foreach ($activities as $activity) {
            $a = $activity->value;
            $t = $current[$a]['totals'];
            $rows[] = [
                'value' => $a,
                'label' => $activity->label(),
                // Le chiffre d'affaires de l'activité seule (un forfait compte pour son activité)
                'revenue' => $t['revenue_by_activity'][$a],
                'previous_revenue' => $previous[$a]['revenue_by_activity'][$a],
                'margin' => $t['margin'],
                'previous_margin' => $previous[$a]['margin'],
                'margin_rate' => $t['margin_rate'],
                'delivered' => $t['delivered'],
                'previous_delivered' => $previous[$a]['delivered'],
                'quality' => $this->qualityOf($company, $activity, $from, $to, $quality),
                'overdue' => isset($payments[$a]) ? $this->overdue($payments[$a]) : null,
                'outstanding' => isset($payments[$a]) ? (int) $payments[$a]['outstanding'] : null,
                'series' => array_map(fn (array $m) => (int) $m['revenue'], $months[$a]),
            ];
        }

        // Toute l'entreprise : le calcul d'ensemble (il compte aussi les coûts sans activité, livreur sans mission…)
        $sum = fn (string $field) => (int) array_sum(array_column($rows, $field));
        $whole = $all ? $this->profitability->build($company, $from, $to)['totals'] : null;
        $wholeBefore = $all ? $this->profitability->build($company, $prevFrom, $prevTo)['totals'] : null;
        $revenue = $sum('revenue');
        $margin = $whole['margin'] ?? $sum('margin');
        $previousRevenue = $sum('previous_revenue');
        $previousMargin = $wholeBefore['margin'] ?? $sum('previous_margin');
        $rate = fn (int $part, int $total) => $total > 0 ? round($part / $total * 100, 1) : null;

        $labels = array_column($months[$values[0]] ?? [], 'label', 'month');

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'previous_period' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
            'estimated' => true,
            'activities' => $rows,
            'totals' => [
                'revenue' => $revenue,
                'previous_revenue' => $previousRevenue,
                'margin' => $margin,
                'previous_margin' => $previousMargin,
                'margin_rate' => $rate($margin, $revenue),
                'previous_margin_rate' => $rate($previousMargin, $previousRevenue),
                'delivered' => $whole['delivered'] ?? $sum('delivered'),
                'previous_delivered' => $wholeBefore['delivered'] ?? $sum('previous_delivered'),
                'outstanding' => (int) array_sum(array_column($payments, 'outstanding')),
                'overdue' => (int) array_sum(array_map(fn (array $p) => $this->overdue($p), $payments)),
                // Coûts que le calcul par activité ne rattache à aucune (écart entre le total et la somme des lignes)
                'unallocated_costs' => $whole ? max(0, $sum('margin') - $whole['margin']) : 0,
            ],
            'months' => collect($labels)->map(fn (string $label, string $month) => [
                'month' => $month,
                'label' => $label,
                'revenue' => collect($values)->mapWithKeys(fn (string $a) => [$a => (int) (collect($months[$a])->firstWhere('month', $month)['revenue'] ?? 0)])->all(),
            ])->values()->all(),
            'decisions' => $this->decisions($company, $values, $current, $quality, $payments, $rows),
        ];
    }

    /**
     * Indicateur de qualité propre à l'activité : taux de livraison (e-commerce), délai tenu (distribution),
     * présence des livreurs mis à disposition (MAD).
     *
     * @param  array<string, mixed>  $quality
     * @return array{label: string, rate: float|null}
     */
    public function qualityOf(Company $company, Activity $activity, CarbonImmutable $from, CarbonImmutable $to, array $quality): array
    {
        if ($activity === Activity::Ecommerce) {
            $kpis = $this->statistics->for($company)->forActivity($activity->value)->overview($from, $to, false)['kpis'];

            return ['label' => 'Taux de livraison', 'rate' => $kpis['success_rate']];
        }
        if ($activity === Activity::Mad) {
            $planned = (int) array_sum(array_column($quality['mad'], 'planned_days'));
            $done = (int) array_sum(array_column($quality['mad'], 'done_days'));

            return ['label' => 'Présence', 'rate' => $planned > 0 ? round($done / $planned * 100, 1) : null];
        }
        $row = collect($quality['delays']['by_activity'])->firstWhere('key', $activity->value);

        return ['label' => 'Délai tenu', 'rate' => $row['rate'] ?? null];
    }

    /**
     * Factures en retard de plus de 30 jours.
     *
     * @param  array<string, mixed>  $payments
     */
    public function overdue(array $payments): int
    {
        return (int) ($payments['buckets']['31_60'] + $payments['buckets']['61_90'] + $payments['buckets']['over_90']);
    }

    /**
     * Points « À décider », du plus grave au moins grave.
     *
     * @param  list<string>  $values
     * @param  list<array<string, mixed>>  $rows  lignes de la vue d'ensemble (hausse du chiffre d'affaires) ; vide ailleurs
     * @param  array<string, array<string, mixed>>  $current  rentabilité de chaque activité
     * @param  array<string, mixed>  $quality
     * @param  array<string, array<string, mixed>>  $payments
     * @return list<array<string, mixed>>
     */
    public function decisions(Company $company, array $values, array $current, array $quality, array $payments, array $rows = []): array
    {
        $out = [];
        $now = CarbonImmutable::now($company->timezone ?: (string) config('app.timezone', 'UTC'));
        $settings = $this->settings->for($company);
        $labels = collect(Activity::cases())->mapWithKeys(fn (Activity $a) => [$a->value => $a->label()]);
        $min = (int) $settings['alerts']['min_volume'];
        $money = fn (int|float $v) => number_format(abs((int) round($v)), 0, ',', ' ').' F';
        $names = fn (Collection $list, callable $text) => $list->take(3)->map($text)->implode(', ').($list->count() > 3 ? '…' : '');
        $add = function (string $level, string $activity, string $topic, string $title, string $body, string $href, string $action, int $weight = 0) use (&$out, $labels) {
            $out[] = ['level' => $level, 'activity' => $activity, 'activity_label' => $labels[$activity] ?? null, 'topic' => $topic,
                'title' => $title, 'body' => str_replace('….', '…', $body), 'href' => $href, 'action' => $action, 'weight' => $weight];
        };

        foreach ($values as $a) {
            $data = $current[$a];

            // Zones livrées à perte (e-commerce : chaque course a son prix ; ailleurs le forfait n'est pas rattaché aux zones)
            $zones = collect($a === 'ecommerce' ? $data['zones'] : [])->filter(fn ($z) => $z['margin'] < 0 && $z['delivered'] >= $min)->sortBy('margin')->values();
            if ($zones->isNotEmpty()) {
                $loss = (int) $zones->sum('margin');
                $add('critical', $a, 'Rentabilité', $zones->count() === 1 ? "1 zone livrée à perte : −{$money($loss)}" : "{$zones->count()} zones livrées à perte : −{$money($loss)}",
                    $names($zones, fn ($z) => "{$z['name']} (−{$money($z['margin'] / $z['delivered'])} par course)").'. '.$zones->sum('delivered').' course(s) livrée(s) sur la période.',
                    "/admin/statistiques?onglet=profitability&activite={$a}", 'Voir la rentabilité', abs($loss));
            }

            // Clients à perte
            $losers = collect($data['clients'])->filter(fn ($c) => $c['verdict'] === 'loss' && $c['revenue'] > 0 && $c['courses'] >= $min)->sortBy('margin')->values();
            if ($losers->isNotEmpty()) {
                $loss = (int) $losers->sum('margin');
                $add('warning', $a, 'Rentabilité', $losers->count() === 1 ? "1 client à perte : −{$money($loss)}" : "{$losers->count()} clients à perte : −{$money($loss)}",
                    $names($losers, fn ($c) => "{$c['name']} (−{$money($c['margin'])})").'.',
                    "/admin/statistiques?onglet=profitability&activite={$a}", 'Voir les clients', abs($loss));
            }

            // Forfaits dépassés ou sous-utilisés
            $contracts = collect($data['contracts'])->filter(fn ($c) => $c['formula'] !== 'distribution_lot');
            foreach (['raise' => ['warning', 'Forfait dépassé', 'à revoir à la hausse'], 'underused' => ['info', 'Forfait sous-utilisé', 'risque de départ']] as $verdict => [$level, $topic, $hint]) {
                $list = $contracts->where('verdict', $verdict)->sortBy('usage', SORT_REGULAR, $verdict === 'raise')->values();
                if ($list->isNotEmpty()) {
                    $add($level, $a, $topic, $list->count() === 1 ? "{$list[0]['merchant']} : {$list[0]['usage']} % du forfait" : "{$list->count()} forfaits {$hint}",
                        $names($list, fn ($c) => "{$c['merchant']} ({$c['usage']} %, {$c['used']} sur {$c['included']})").'. Seuils réglés : au-delà de '.$settings['forfait_high'].' %, sous '.$settings['forfait_low'].' %.',
                        '/admin/contrats', 'Voir les contrats', (int) $list->max(fn ($c) => abs($c['usage'] - 100)));
                }
            }

            // Factures en retard de plus de 30 jours
            if (isset($payments[$a]) && ($late = $this->overdue($payments[$a])) > 0) {
                $clients = collect($payments[$a]['clients'])->filter(fn ($c) => $c['overdue'] > 0)->sortByDesc('overdue')->values();
                $add('warning', $a, 'Trésorerie', "{$money($late)} de factures en retard de plus de 30 jours",
                    $names($clients, fn ($c) => "{$c['name']} ({$money($c['overdue'])} en retard)").'.',
                    '/admin/factures', 'Voir les factures', $late);
            }

            // Clients dont le volume baisse
            $drop = (int) ($settings['alerts']['client_drop_percent'] ?? 0);
            if ($drop > 0) {
                $orders = fn () => Order::forCompany($company->id)->where('activity', $a);
                $falling = collect($this->insights->decline($company, $orders, $now))->filter(fn ($c) => $c['change'] <= -$drop)->values();
                if ($falling->isNotEmpty()) {
                    $add('info', $a, 'Clients', $falling->count() === 1 ? "{$falling[0]['name']} : {$falling[0]['change']} % de volume en 30 jours" : "{$falling->count()} clients en forte baisse sur 30 jours",
                        $names($falling, fn ($c) => "{$c['name']} ({$c['change']} %, {$c['recent']} course(s) contre {$c['average']} par mois)").'.',
                        "/admin/statistiques?onglet=clients&activite={$a}", 'Voir les clients', (int) abs($falling->min('change')));
                }
            }

            // Bonne nouvelle : forte hausse du chiffre d'affaires
            $row = collect($rows)->firstWhere('value', $a);
            if ($row !== null && $row['previous_revenue'] > 0 && ($growth = (int) round(($row['revenue'] / $row['previous_revenue'] - 1) * 100)) >= self::GROWTH_GOOD) {
                $add('good', $a, 'Croissance', "Chiffre d'affaires en hausse de {$growth} %",
                    "{$money($row['revenue'])} contre {$money($row['previous_revenue'])} sur la période précédente.",
                    "/admin/statistiques?onglet=goals&activite={$a}", 'Voir les tendances', $growth);
            }
        }

        if (in_array('distribution', $values, true)) {
            // Lots en retard sur leur échéance
            $late = collect($quality['lots'])->where('status', 'late')->filter(fn ($l) => $l['delivered'] < $l['quantity'])->values();
            if ($late->isNotEmpty()) {
                $add('critical', 'distribution', 'Lot en retard', $late->count() === 1 ? "{$late[0]['name']} : échéance dépassée" : "{$late->count()} lots ont dépassé leur échéance",
                    $names($late, fn ($l) => "{$l['name']} ({$l['delivered']} sur {$l['quantity']} livrés, échéance le ".CarbonImmutable::parse($l['due_on'])->format('d/m').')').'.',
                    '/admin/statistiques?onglet=quality&activite=distribution', 'Voir les lots', (int) $late->sum(fn ($l) => $l['quantity'] - $l['delivered']));
            }
        }

        if (in_array('mad', $values, true)) {
            // Présence insuffisante chez un client MAD
            $absent = collect($quality['mad'])->filter(fn ($p) => $p['rate'] !== null && $p['planned_days'] > 0 && $p['rate'] < self::MAD_PRESENCE_MIN)->sortBy('rate')->values();
            if ($absent->isNotEmpty()) {
                $add('warning', 'mad', 'Présence', $absent->count() === 1 ? "{$absent[0]['merchant']} : {$absent[0]['rate']} % de présence" : "{$absent->count()} contrats MAD sous ".self::MAD_PRESENCE_MIN.' % de présence',
                    $names($absent, fn ($p) => "{$p['merchant']} ({$p['done_days']} jour(s) faits sur {$p['planned_days']})").'.',
                    '/admin/statistiques?onglet=quality&activite=mad', 'Voir la présence', (int) round(100 - $absent->min('rate')));
            }
        }

        // Contrats qui arrivent à échéance
        $ending = BillingPlan::forCompany($company->id)->whereIn('activity', array_intersect($values, ['distribution', 'mad']))
            ->where('formula', '!=', BillingFormula::DistributionLot->value)->whereNotNull('ends_on')
            ->whereDate('ends_on', '>=', $now->toDateString())->whereDate('ends_on', '<=', $now->addDays(self::RENEWAL_DAYS)->toDateString())
            ->with('merchant:id,business_name')->orderBy('ends_on')->get();
        foreach ($ending->groupBy(fn (BillingPlan $p) => $p->activity->value) as $a => $plans) {
            $add('warning', $a, 'Renouvellement', $plans->count() === 1 ? "{$plans[0]->merchant?->business_name} : contrat à échéance le {$plans[0]->ends_on->format('d/m/Y')}" : "{$plans->count()} contrats à renouveler sous ".self::RENEWAL_DAYS.' jours',
                $names($plans->values(), fn (BillingPlan $p) => "{$p->merchant?->business_name} (le {$p->ends_on->format('d/m/Y')}, ".number_format((int) $p->monthly_fee, 0, ',', ' ').' F par mois)').'.',
                '/admin/contrats', 'Voir les contrats', (int) $plans->sum('monthly_fee'));
        }

        usort($out, fn (array $x, array $y) => [self::LEVELS[$x['level']], -$x['weight']] <=> [self::LEVELS[$y['level']], -$y['weight']]);

        return array_map(fn (array $d) => collect($d)->except('weight')->all(), $out);
    }
}
