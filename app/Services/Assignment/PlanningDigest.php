<?php

namespace App\Services\Assignment;

use App\Enums\BillingFormula;
use App\Models\BillingPlan;
use App\Models\Company;
use App\Models\CourierRule;
use App\Models\User;
use App\Notifications\PlanningDigestNotice;
use App\Services\Orders\ActivityOptions;
use Carbon\CarbonImmutable;

/**
 * Message du matin : chaque livreur reçoit ce qu'il couvre aujourd'hui (ou ce qu'il remplace), une fois par jour,
 * à partir de 6 h 45 (heure de l'entreprise).
 */
class PlanningDigest
{
    public const FROM = '06:45';

    public function __construct(
        private readonly CourierPlanning $planning,
        private readonly ActivityOptions $options,
    ) {}

    public function run(): int
    {
        $sent = 0;

        Company::query()->where('status', 'active')
            ->where(fn ($q) => $q->whereHas('courierRules', fn ($q) => $q->where('is_active', true))
                ->orWhereExists(fn ($q) => $q->from('billing_plans')->whereColumn('billing_plans.company_id', 'companies.id')
                    ->where('formula', BillingFormula::MadContract->value)))
            ->each(function (Company $company) use (&$sent) {
                $now = CarbonImmutable::now($company->timezone ?: config('app.timezone'));
                $today = $now->toDateString();
                if ($now->format('H:i') < self::FROM || $company->planning_digest_sent_on?->toDateString() === $today) {
                    return;
                }

                foreach ($this->linesFor($company->id, $today) as $entry) {
                    $entry['user']->notify(new PlanningDigestNotice($today, $entry['lines']));
                    $sent++;
                }
                $company->forceFill(['planning_digest_sent_on' => $today])->save();
            });

        return $sent;
    }

    /**
     * Par livreur : « Ramassage : Cocody, Riviera », « Remplace Awa : Boutique Chic »…
     *
     * @return list<array{user: User, lines: list<string>}>
     */
    public function linesFor(int $companyId, string $date): array
    {
        $absent = $this->planning->absentOn($companyId, $date);
        $byCourier = [];

        foreach ([CourierRule::PICKUP => 'Ramassage', CourierRule::DELIVERY => 'Livraison'] as $type => $word) {
            foreach ($this->planning->rulesOn($companyId, $type, $date) as $rule) {
                $where = explode(' · ', $this->planning->describe($rule))[0];
                if ($this->planning->available($rule->courier, $absent)) {
                    $byCourier[$rule->courier_id]['user'] = $rule->courier->user;
                    $byCourier[$rule->courier_id]['lines'][] = "{$word} : {$where}";
                } elseif ($rule->backup && $this->planning->available($rule->backup, $absent)) {
                    $byCourier[$rule->backup_courier_id]['user'] = $rule->backup->user;
                    $byCourier[$rule->backup_courier_id]['lines'][] = "{$word} (remplace {$rule->courier?->user?->name}) : {$where}";
                }
            }
        }

        // Mise à disposition (MAD) ce jour-là : chez quel client, à quelles heures
        $day = CarbonImmutable::parse($date)->dayOfWeekIso;
        $company = Company::query()->find($companyId);
        BillingPlan::withoutGlobalScopes()->where('company_id', $companyId)->where('formula', BillingFormula::MadContract->value)
            ->activeOn($date)->with(['couriers.user', 'merchant:id,business_name'])->get()
            ->each(function (BillingPlan $plan) use (&$byCourier, $absent, $day, $company) {
                $o = $this->options->plan($plan, $company);
                if (! in_array($day, array_map('intval', $o['mad_days']), true)) {
                    return;
                }
                foreach ($plan->couriers as $courier) {
                    if (in_array($courier->id, $absent, true) || $courier->user === null) {
                        continue;
                    }
                    $byCourier[$courier->id]['user'] = $courier->user;
                    $byCourier[$courier->id]['lines'][] = 'Mise à disposition : '.$plan->merchant?->business_name.($o['mad_from'] ? " ({$o['mad_from']}–{$o['mad_to']})" : '');
                }
            });

        return array_values(array_filter($byCourier, fn ($e) => isset($e['user'])));
    }
}
