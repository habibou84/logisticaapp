<?php

namespace App\Services\Shifts;

use App\Enums\BillingFormula;
use App\Models\BillingPlan;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierRoutine;
use App\Models\CourierRoutineStop;
use App\Services\Assignment\CourierPlanning;
use App\Services\Orders\ActivityOptions;
use Carbon\CarbonImmutable;

/**
 * Où et à quelle heure un livreur doit pointer un jour donné :
 *  1. en MAD ce jour-là (contrat en cours, jour prévu au contrat) avec un parcours pour ce jour et ce livreur :
 *     la première étape du parcours (le plus tôt s'il y en a plusieurs), à l'heure du parcours ou du contrat ;
 *  2. en MAD sans parcours : le siège du client, à l'heure de début du contrat ;
 *  3. sinon (distribution, e-commerce) : l'agence de l'entreprise, à l'heure de début de l'agence, ses jours de travail.
 * Absent ce jour-là, pointage désactivé, ou jour où l'agence ne travaille pas : rien (null).
 */
class ShiftPlace
{
    public function __construct(
        private readonly ShiftSettings $settings,
        private readonly ActivityOptions $options,
        private readonly CourierPlanning $planning,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function for(Courier $courier, ?string $date = null): ?array
    {
        $company = Company::query()->find($courier->company_id);
        $settings = $this->settings->for($company);
        if (! $settings['enabled']) {
            return null;
        }
        $tz = $company?->timezone ?: config('app.timezone');
        $day = CarbonImmutable::parse($date ?? CarbonImmutable::now($tz)->toDateString(), $tz)->startOfDay();
        if (in_array($courier->id, $this->planning->absentOn($courier->company_id, $day->toDateString()), true)) {
            return null;
        }

        return $this->mad($courier, $company, $day, $settings) ?? $this->agency($day, $settings);
    }

    /**
     * Lieux attendus de plusieurs livreurs le même jour (écran des pointages).
     *
     * @param  iterable<Courier>  $couriers
     * @return array<int, array<string, mixed>|null>
     */
    public function forMany(iterable $couriers, string $date): array
    {
        $out = [];
        foreach ($couriers as $courier) {
            $out[$courier->id] = $this->for($courier, $date);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    private function mad(Courier $courier, ?Company $company, CarbonImmutable $day, array $settings): ?array
    {
        $plans = BillingPlan::withoutGlobalScopes()->where('company_id', $courier->company_id)
            ->where('formula', BillingFormula::MadContract->value)->activeOn($day->toDateString())
            ->whereHas('couriers', fn ($q) => $q->whereKey($courier->id))
            ->with('merchant')->orderBy('starts_on')->orderBy('id')->get()
            ->filter(fn (BillingPlan $p) => in_array($day->dayOfWeekIso, array_map('intval', $this->options->plan($p, $company)['mad_days'] ?? []), true));
        if ($plans->isEmpty()) {
            return null;
        }

        // Parcours du jour pour ce livreur, sur l'un de ses contrats : le plus tôt d'abord
        $candidates = [];
        foreach ($plans as $plan) {
            $from = $this->options->plan($plan, $company)['mad_from'] ?? null;
            $routines = CourierRoutine::withoutGlobalScopes()->where('billing_plan_id', $plan->id)->where('is_active', true)
                ->with(['stops.destination'])->orderBy('id')->get()
                ->filter(fn (CourierRoutine $r) => $r->runsOn($day->dayOfWeekIso) && $r->concerns($courier) && $r->stops->isNotEmpty());
            foreach ($routines as $routine) {
                $candidates[] = ['plan' => $plan, 'routine' => $routine, 'time' => $routine->starts_at ?: $from];
            }
            if ($routines->isEmpty()) {
                $candidates[] = ['plan' => $plan, 'routine' => null, 'time' => $from];
            }
        }
        // Un parcours passe avant le simple siège ; puis l'heure la plus tôt (sans heure : en dernier)
        usort($candidates, fn ($a, $b) => [$a['routine'] === null, $a['time'] ?? '99:99'] <=> [$b['routine'] === null, $b['time'] ?? '99:99']);
        ['plan' => $plan, 'routine' => $routine, 'time' => $time] = $candidates[0];
        $merchant = $plan->merchant;

        if ($routine !== null) {
            /** @var CourierRoutineStop $first */
            $first = $routine->stops->first();
            $place = $first->place($merchant);
            $stops = $routine->stops->map(fn (CourierRoutineStop $s) => [
                'position' => $s->position,
                'action' => $s->action,
                'action_label' => CourierRoutineStop::ACTION_LABELS[$s->action] ?? $s->action,
                'instructions' => $s->instructions,
                'expected_time' => $s->expected_time,
                ...$s->place($merchant),
            ])->values()->all();
        } else {
            $place = [
                'label' => 'Siège · '.$merchant?->business_name,
                'address' => $merchant ? trim(implode(', ', array_filter([$merchant->pickup_address, $merchant->pickup_landmark]))) ?: null : null,
                'lat' => $merchant?->pickup_lat,
                'lng' => $merchant?->pickup_lng,
            ];
            $stops = [];
        }

        return [
            'kind' => 'mad',
            'work_date' => $day->toDateString(),
            'billing_plan_id' => $plan->id,
            'merchant_id' => $merchant?->id,
            'merchant' => $merchant?->business_name,
            'routine_id' => $routine?->id,
            'routine' => $routine?->name,
            ...$place,
            'expected_at' => $this->at($day, $time),
            'radius' => (int) $settings['radius'],
            'stops' => $stops,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    private function agency(CarbonImmutable $day, array $settings): ?array
    {
        if (! in_array($day->dayOfWeekIso, array_map('intval', (array) $settings['agency_days']), true)) {
            return null;
        }

        return [
            'kind' => 'agency',
            'work_date' => $day->toDateString(),
            'billing_plan_id' => null,
            'merchant_id' => null,
            'merchant' => null,
            'routine_id' => null,
            'routine' => null,
            'label' => $settings['agency_name'] ?: 'Agence',
            'address' => $settings['agency_address'],
            'lat' => $settings['agency_lat'] !== null ? (float) $settings['agency_lat'] : null,
            'lng' => $settings['agency_lng'] !== null ? (float) $settings['agency_lng'] : null,
            'expected_at' => $this->at($day, $settings['agency_start']),
            'radius' => (int) $settings['radius'],
            'stops' => [],
        ];
    }

    /** Heure prévue de ce jour-là (heure de l'entreprise), ou null sans heure */
    private function at(CarbonImmutable $day, ?string $time): ?CarbonImmutable
    {
        if (! $time || ! preg_match('/^\d{2}:\d{2}$/', $time)) {
            return null;
        }
        [$h, $m] = array_map('intval', explode(':', $time));

        return $day->setTime($h, $m);
    }
}
