<?php

namespace App\Services\Finance;

use App\Enums\EarningType;
use App\Enums\PaymentMethod;
use App\Enums\PayoutStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\CourierAdvance;
use App\Models\CourierEarning;
use App\Models\CourierPayout;
use App\Models\PayPlan;
use App\Models\PayPlanBonus;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Paie des livreurs : regroupe les gains non payés (courses, salaire, primes, retenues)
 * en fiches de paie, à la demande ou à la fin de chaque période du plan.
 */
class CourierPayroll
{
    public const COMPENSATION = 'compensation';

    /**
     * @return array{unpaid: int, cash_in_hand: int}
     */
    public function summary(Courier $courier): array
    {
        return [
            'unpaid' => (int) $courier->earnings()->whereNull('payout_id')->sum('amount'),
            'cash_in_hand' => app(CourierCash::class)->balance($courier)['due'],
        ];
    }

    /**
     * Fiche de paie à la demande : tous les gains non payés du livreur.
     */
    public function create(User $actor, Courier $courier): CourierPayout
    {
        return DB::transaction(function () use ($actor, $courier) {
            $earnings = CourierEarning::where('courier_id', $courier->id)->whereNull('payout_id')->lockForUpdate()->get();

            if ($earnings->isEmpty()) {
                throw new BusinessRuleException('Aucun gain à payer pour ce livreur.', 'courier_id');
            }

            $payout = $this->build($courier, $earnings, $earnings->min('created_at'), $earnings->max('created_at'), $actor);
            if ($payout === null) {
                throw new BusinessRuleException('Aucun gain à payer : les retenues attendent les prochains gains (plafond des retenues).', 'courier_id');
            }

            return $payout;
        });
    }

    /**
     * Clôture une période de paie : salaire de base de la période, gains non payés
     * jusqu'à sa fin, plafond des retenues. Une seule fiche automatique par livreur
     * et par période, même annulée (rien n'est recréé derrière la caisse).
     */
    public function closePeriod(Courier $courier, PayPlan $plan, CarbonInterface $start, CarbonInterface $end): ?CourierPayout
    {
        return DB::transaction(function () use ($courier, $plan, $start, $end) {
            Courier::query()->withoutGlobalScopes()->lockForUpdate()->find($courier->id);

            if (CourierPayout::query()->withoutGlobalScopes()->where('courier_id', $courier->id)->where('automatic', true)
                ->whereDate('period_end', $end->toDateString())->exists()) {
                return null;
            }

            // Salaire et primes de la période : créés maintenant, mais rattachés à cette fiche
            $extra = collect([$this->salary($courier, $plan, $start, $end), ...$this->bonuses($courier, $plan, $start, $end)])
                ->filter()->map->getKey()->all();

            [, $until] = app(CourierActivity::class)->range($courier->company_id, $start, $end);
            $earnings = CourierEarning::query()->withoutGlobalScopes()
                ->where('courier_id', $courier->id)->whereNull('payout_id')
                ->where(fn ($q) => $q->where('created_at', '<=', $until)
                    ->when($extra, fn ($q) => $q->orWhereIn('id', $extra)))
                ->lockForUpdate()->get();

            if ($earnings->isEmpty()) {
                return null;
            }

            return $this->build($courier, $earnings, $start, $end, null, automatic: true);
        });
    }

    /**
     * Salaire de base de la période, au prorata des jours si le livreur est arrivé en cours de période.
     */
    private function salary(Courier $courier, PayPlan $plan, CarbonInterface $start, CarbonInterface $end): ?CourierEarning
    {
        if ($plan->base_salary <= 0) {
            return null;
        }

        $existing = CourierEarning::query()->withoutGlobalScopes()->where('courier_id', $courier->id)
            ->where('type', EarningType::Salary->value)->whereDate('period_start', $start->toDateString())->first();
        if ($existing) {
            return $existing->payout_id === null ? $existing : null;
        }

        $days = $start->diffInDays($end) + 1;
        $from = CarbonImmutable::parse($courier->created_at->toDateString())->max($start);
        $worked = $from->greaterThan($end) ? 0 : (int) $from->diffInDays($end) + 1;
        if ($worked === 0) {
            return null;
        }

        $amount = (int) round($plan->base_salary * $worked / $days);
        $period = $start->format('d/m').' → '.$end->format('d/m/Y');

        return CourierEarning::create([
            'company_id' => $courier->company_id,
            'courier_id' => $courier->id,
            'type' => EarningType::Salary,
            'amount' => $amount,
            'pay_plan_id' => $plan->id,
            'description' => 'Salaire de base '.$period,
            'detail' => $worked < $days
                ? sprintf('%s × %d/%d jours (arrivée en cours de période)', Money::format($plan->base_salary), $worked, $days)
                : 'Salaire de base de la période',
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
        ]);
    }

    /**
     * Primes d'objectifs atteintes sur la période (une seule fois par période).
     *
     * @return list<CourierEarning>
     */
    private function bonuses(Courier $courier, PayPlan $plan, CarbonInterface $start, CarbonInterface $end): array
    {
        $plan->loadMissing('bonuses');
        if ($plan->bonuses->isEmpty() || CourierEarning::query()->withoutGlobalScopes()->where('courier_id', $courier->id)
            ->where('type', EarningType::Bonus->value)->whereDate('period_start', $start->toDateString())->exists()) {
            return [];
        }

        $bonuses = app(PayBonuses::class);
        $measures = $bonuses->measure($courier, $start, $end);

        return $bonuses->reached($plan->bonuses, $measures)->map(fn (PayPlanBonus $bonus) => CourierEarning::create([
            'company_id' => $courier->company_id,
            'courier_id' => $courier->id,
            'type' => EarningType::Bonus,
            'amount' => $bonus->amount,
            'pay_plan_id' => $plan->id,
            'description' => $bonus->title(),
            'detail' => sprintf('Objectif %s atteint : %d', $bonus->metric->goal($bonus->threshold), $measures[$bonus->metric->value]).($bonus->metric->value === 'success_rate' ? ' %' : ''),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
        ]))->all();
    }

    /**
     * Crée la fiche avec les gains, après application du plafond des retenues.
     *
     * @param  Collection<int, CourierEarning>  $earnings
     */
    private function build(Courier $courier, Collection $earnings, CarbonInterface|string $start, CarbonInterface|string $end, ?User $actor, bool $automatic = false): ?CourierPayout
    {
        $plan = app(CourierPay::class)->planFor($courier);
        // Un report positif (paire d'une fiche annulée) réduit les retenues, il n'est pas un gain
        $carriedBack = (int) $earnings->where('type', EarningType::Carryover)->where('amount', '>', 0)->sum('amount');
        $gains = (int) $earnings->where('amount', '>', 0)->sum('amount') - $carriedBack;
        $deductions = -(int) $earnings->where('amount', '<', 0)->sum('amount') - $carriedBack;
        $cap = $plan?->deduction_cap_percent;
        $excess = $cap !== null ? max(0, $deductions - intdiv($gains * $cap, 100)) : 0;

        // Rien à payer : uniquement des retenues, toutes reportées
        if ($cap !== null && $gains === 0) {
            return null;
        }

        $payout = CourierPayout::create([
            'company_id' => $courier->company_id,
            'courier_id' => $courier->id,
            'reference' => 'TMP-'.Str::random(20),
            'period_start' => $start,
            'period_end' => $end,
            'amount' => $earnings->sum('amount') + $excess,
            'status' => PayoutStatus::Draft,
            'automatic' => $automatic,
            'created_by' => $actor?->id,
        ]);
        $payout->forceFill(['reference' => sprintf('PL-%s-%05d', now()->format('ymd'), $payout->id)])->save();

        CourierEarning::query()->withoutGlobalScopes()->whereIn('id', $earnings->modelKeys())->update(['payout_id' => $payout->id]);

        if ($excess > 0) {
            $detail = sprintf('Retenues limitées à %d %% des gains (%s) : %s reportés', $cap, Money::format($gains), Money::format($excess));
            foreach ([[$excess, $payout->id, 'Retenue reportée à la paie suivante'], [-$excess, null, 'Retenue reportée de '.$payout->reference]] as [$amount, $payoutId, $description]) {
                CourierEarning::create([
                    'company_id' => $courier->company_id,
                    'courier_id' => $courier->id,
                    'type' => EarningType::Carryover,
                    'amount' => $amount,
                    'payout_id' => $payoutId,
                    'description' => $description,
                    'detail' => $detail,
                    'created_by' => $actor?->id,
                ]);
            }
        }

        return $payout;
    }

    /**
     * Paiement de la fiche. « compensation » : le livreur garde le montant sur l'argent
     * encaissé qu'il détient ; ce qu'il doit verser à la caisse diminue d'autant.
     */
    public function markPaid(User $actor, CourierPayout $payout, PaymentMethod|string $method, ?string $reference = null): CourierPayout
    {
        return DB::transaction(function () use ($actor, $payout, $method, $reference) {
            $payout = CourierPayout::query()->lockForUpdate()->findOrFail($payout->id);
            $this->ensureDraft($payout);
            $compensated = $method === self::COMPENSATION;

            if ($compensated) {
                $due = app(CourierCash::class)->balance($payout->courier)['due'];
                if ($payout->amount <= 0) {
                    throw new BusinessRuleException('Rien à garder sur l\'encaissé : le montant de la fiche n\'est pas positif.', 'method');
                }
                if ($due < $payout->amount) {
                    throw new BusinessRuleException(sprintf('Le livreur n\'a que %s d\'argent encaissé en main : payez-le autrement.', Money::format(max(0, $due))), 'method');
                }

                CourierAdvance::create([
                    'company_id' => $payout->company_id,
                    'courier_id' => $payout->courier_id,
                    'courier_payout_id' => $payout->id,
                    'amount' => -$payout->amount,
                    'reason' => 'Paie '.$payout->reference.' gardée sur l\'encaissé',
                    'given_by' => $actor->id,
                    'given_at' => now(),
                ]);
            }

            $payout->forceFill([
                'status' => PayoutStatus::Paid,
                'method' => $compensated ? null : $method,
                'compensated' => $compensated,
                'transaction_ref' => $reference,
                'paid_by' => $actor->id,
                'paid_at' => now(),
            ])->save();

            return $payout;
        });
    }

    public function cancel(CourierPayout $payout): CourierPayout
    {
        return DB::transaction(function () use ($payout) {
            $payout = CourierPayout::query()->lockForUpdate()->findOrFail($payout->id);
            $this->ensureDraft($payout);

            // Report de retenue : la paire (+ et −) revient aussi en attente et s'annule
            $payout->earnings()->update(['payout_id' => null]);
            $payout->forceFill(['status' => PayoutStatus::Cancelled])->save();

            return $payout;
        });
    }

    public function adjust(User $actor, Courier $courier, int $amount, string $description): CourierEarning
    {
        if ($amount === 0) {
            throw new BusinessRuleException('Le montant de l\'ajustement ne peut pas être nul.', 'amount');
        }

        return CourierEarning::create([
            'company_id' => $courier->company_id,
            'courier_id' => $courier->id,
            'type' => EarningType::Adjustment,
            'amount' => $amount,
            'description' => $description,
            'created_by' => $actor->id,
        ]);
    }

    private function ensureDraft(CourierPayout $payout): void
    {
        if ($payout->status !== PayoutStatus::Draft) {
            throw new BusinessRuleException("Cette paie est déjà « {$payout->status->label()} ».", 'status');
        }
    }
}
