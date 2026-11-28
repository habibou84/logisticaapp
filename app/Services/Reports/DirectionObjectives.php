<?php

namespace App\Services\Reports;

use App\Enums\Activity;
use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * Statistiques › Objectifs : les objectifs du mois, par activité et pour les équipes, comparés au réalisé.
 *
 * Les objectifs valent pour chaque mois jusqu'à ce qu'on les change. Mois en cours : le réalisé à ce jour et, pour
 * ce qui se cumule (chiffre d'affaires, marge, courses livrées), l'estimation de fin de mois au rythme actuel ; c'est
 * elle qui dit si l'objectif est en bonne voie. Mois passé : le réalisé du mois entier.
 */
class DirectionObjectives
{
    public function __construct(
        private readonly DirectionBoard $board,
        private readonly DirectionTeams $teams,
        private readonly Profitability $profitability,
        private readonly ServiceQuality $quality,
        private readonly StatsSettings $settings,
    ) {}

    /**
     * @param  list<Activity>  $activities  activités visibles (périmètre de la personne)
     * @return array<string, mixed>
     */
    public function build(Company $company, CarbonImmutable $month, array $activities): array
    {
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $from = CarbonImmutable::parse($month->format('Y-m-01'));
        $current = $from->format('Y-m') === $today->format('Y-m');
        $to = $current ? CarbonImmutable::parse($today->toDateString()) : $from->endOfMonth()->startOfDay();
        $targets = $this->settings->for($company)['objectives'];

        // Fin de mois estimée : au rythme des jours écoulés (forfaits et frais fixes sont déjà comptés au prorata des jours)
        $scale = fn (int|float|null $v) => $current && $v !== null ? (int) round($v * $from->daysInMonth / $today->day) : null;

        $groups = [];
        foreach ($activities as $activity) {
            $a = $activity->value;
            $totals = $this->profitability->build($company, $from, $to, $a)['totals'];
            $quality = $this->board->qualityOf($company, $activity, $from, $to, $activity === Activity::Ecommerce ? [] : $this->quality->build($company, $from, $to, $a));
            $realized = ['revenue' => $totals['revenue_by_activity'][$a], 'margin' => $totals['margin'], 'delivered' => $totals['delivered'], 'quality' => $quality['rate']];

            $groups[] = [
                'scope' => $a,
                'label' => $activity->label(),
                'rows' => collect(StatsSettings::OBJECTIVES['activity'])->map(fn (array $metric, string $key) => $this->row(
                    $key, $key === 'quality' ? $quality['label'] : $metric['label'], $metric, $targets[$a][$key], $realized[$key], $key === 'quality' ? null : $scale($realized[$key]), $current,
                ))->values()->all(),
            ];
        }

        $kpis = $this->teams->build($company, $from, $to)['kpis'];
        $groups[] = [
            'scope' => 'teams',
            'label' => 'Équipes',
            'rows' => collect(StatsSettings::OBJECTIVES['teams'])->map(fn (array $metric, string $key) => $this->row(
                $key, $metric['label'], $metric, $targets['teams'][$key], $kpis[$key], null, $current,
            ))->values()->all(),
        ];

        return [
            'month' => $from->format('Y-m'),
            'label' => ucfirst($from->locale('fr')->isoFormat('MMMM YYYY')),
            'current' => $current,
            'elapsed_days' => $current ? $today->day : $from->daysInMonth,
            'days_in_month' => $from->daysInMonth,
            'groups' => $groups,
            'set' => collect($groups)->flatMap(fn ($g) => $g['rows'])->whereNotNull('target')->count(),
        ];
    }

    /**
     * Un objectif face à son résultat. État : « ok » (atteint, ou en bonne voie ce mois-ci), « behind », null sans
     * objectif ou sans mesure.
     *
     * @param  array{label: string, kind: string, higher: bool}  $metric
     * @return array<string, mixed>
     */
    private function row(string $key, string $label, array $metric, int|float|null $target, int|float|null $realized, int|float|null $projection, bool $current): array
    {
        // Ce qui se cumule se juge sur l'estimation de fin de mois ; un taux ou une moyenne, sur sa valeur à ce jour
        $judged = $projection ?? $realized;
        $status = null;
        $percent = null;
        if ($target !== null && $judged !== null) {
            $status = ($metric['higher'] ? $judged >= $target : $judged <= $target) ? 'ok' : 'behind';
        }
        if ($target !== null && $realized !== null) {
            // Part de l'objectif atteinte ; pour un plafond (temps, paie par course) : 100 % quand on est en dessous
            $percent = $metric['higher']
                ? ($target > 0 ? max(0, (int) round($realized / $target * 100)) : null)
                : ($realized > 0 ? (int) round($target / $realized * 100) : 100);
        }

        return [
            'key' => $key, 'label' => $label, 'kind' => $metric['kind'], 'higher_is_better' => $metric['higher'],
            'target' => $target, 'realized' => $realized, 'projection' => $current ? $projection : null,
            'percent' => $percent, 'status' => $status,
        ];
    }
}
