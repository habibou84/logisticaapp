<?php

namespace App\Services\Finance;

use App\Enums\BillingFormula;
use App\Models\BillingPlan;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierAbsence;
use App\Services\Orders\ActivityOptions;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

/**
 * Jours de mise à disposition (MAD) d'un livreur sur une période : jours où l'un de ses contrats MAD
 * est en cours et le prévoit (jours du contrat), sans ses absences. Un jour compte une fois.
 */
class MadDays
{
    public function __construct(private readonly ActivityOptions $options) {}

    /**
     * @return array{total: int, clients: list<string>}
     */
    public function count(Courier $courier, CarbonInterface $start, CarbonInterface $end): array
    {
        $from = CarbonImmutable::parse($start->toDateString());
        $to = CarbonImmutable::parse($end->toDateString());
        $company = Company::query()->find($courier->company_id);

        $plans = BillingPlan::withoutGlobalScopes()->where('company_id', $courier->company_id)
            ->where('formula', BillingFormula::MadContract->value)
            ->overlapping($from->toDateString(), $to->toDateString())
            ->whereHas('couriers', fn ($q) => $q->whereKey($courier->id))
            ->with('merchant:id,business_name')->get();
        if ($plans->isEmpty()) {
            return ['total' => 0, 'clients' => []];
        }

        $absences = CourierAbsence::withoutGlobalScopes()->where('courier_id', $courier->id)
            ->whereDate('starts_on', '<=', $to->toDateString())->whereDate('ends_on', '>=', $from->toDateString())->get();
        $absent = fn (string $day) => $absences->contains(fn (CourierAbsence $a) => $a->starts_on->toDateString() <= $day && $a->ends_on->toDateString() >= $day);

        $days = [];
        $clients = [];
        foreach ($plans as $plan) {
            $weekdays = array_map('intval', $this->options->plan($plan, $company)['mad_days']);
            $first = $from->max(CarbonImmutable::parse($plan->starts_on->toDateString()));
            $last = $plan->ends_on ? $to->min(CarbonImmutable::parse($plan->ends_on->toDateString())) : $to;
            foreach (CarbonPeriod::create($first, $last) as $day) {
                $iso = $day->toDateString();
                if (in_array($day->dayOfWeekIso, $weekdays, true) && ! $absent($iso)) {
                    $days[$iso] = true;
                    $clients[$plan->merchant?->business_name] = true;
                }
            }
        }

        return ['total' => count($days), 'clients' => array_keys(array_filter($clients, fn ($v, $k) => $k !== '', ARRAY_FILTER_USE_BOTH))];
    }
}
