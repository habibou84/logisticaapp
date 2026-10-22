<?php

namespace App\Services\Finance;

use App\Enums\PayBonusMetric;
use App\Models\Courier;
use App\Models\PayPlan;
use App\Models\PayPlanBonus;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Primes d'objectifs : pour chaque indicateur, le palier le plus élevé atteint sur la
 * période est payé ; les primes d'indicateurs différents s'additionnent.
 */
class PayBonuses
{
    public function __construct(private CourierActivity $activity) {}

    /**
     * Indicateurs d'un livreur sur une période.
     *
     * @return array{deliveries: int, pickups: int, attempts: int, success_rate: int, worked_days: int}
     */
    public function measure(Courier $courier, CarbonInterface $start, CarbonInterface $end): array
    {
        return $this->activity->measures(
            $this->activity->events($courier->company_id, [$courier->id], $start, $end),
            $this->activity->timezone($courier->company_id),
        );
    }

    /**
     * Primes gagnées (un palier par indicateur).
     *
     * @param  Collection<int, PayPlanBonus>  $bonuses
     * @param  array<string, int>  $measures
     * @return Collection<int, PayPlanBonus>
     */
    public function reached(Collection $bonuses, array $measures): Collection
    {
        return $bonuses
            ->filter(fn (PayPlanBonus $b) => $this->achieved($b, $measures))
            ->groupBy(fn (PayPlanBonus $b) => $b->metric->value)
            ->map(fn (Collection $tiers) => $tiers->sortByDesc('threshold')->first())
            ->values();
    }

    /**
     * Progression vers les objectifs, pour le livreur.
     *
     * @param  array<string, int>  $measures
     * @return list<array{metric: string, label: string, value: int, tiers: list<array{threshold: int, amount: int, reached: bool}>, earned: int}>
     */
    public function progress(PayPlan $plan, array $measures): array
    {
        return $plan->bonuses->groupBy(fn (PayPlanBonus $b) => $b->metric->value)
            ->map(function (Collection $tiers, string $metric) use ($measures) {
                $best = $this->reached($tiers, $measures)->first();

                return [
                    'metric' => $metric,
                    'label' => PayBonusMetric::from($metric)->label(),
                    'value' => $measures[$metric] ?? 0,
                    'min_count' => $tiers->max('min_count'),
                    'attempts' => $measures['attempts'] ?? 0,
                    'tiers' => $tiers->sortBy('threshold')->map(fn (PayPlanBonus $b) => [
                        'threshold' => $b->threshold,
                        'amount' => $b->amount,
                        'reached' => $this->achieved($b, $measures),
                    ])->values()->all(),
                    'earned' => $best?->amount ?? 0,
                ];
            })->values()->all();
    }

    /**
     * @param  array<string, int>  $measures
     */
    private function achieved(PayPlanBonus $bonus, array $measures): bool
    {
        if ($bonus->metric === PayBonusMetric::SuccessRate && ($measures['attempts'] ?? 0) < max(1, (int) $bonus->min_count)) {
            return false;
        }

        return ($measures[$bonus->metric->value] ?? 0) >= $bonus->threshold;
    }
}
