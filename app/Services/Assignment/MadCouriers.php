<?php

namespace App\Services\Assignment;

use App\Enums\AssignmentType;
use App\Enums\BillingFormula;
use App\Models\BillingPlan;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Order;
use App\Services\Orders\ActivityOptions;
use Carbon\CarbonImmutable;

/**
 * Mise à disposition (MAD) : les courses du client vont à ses livreurs attitrés, ramassage et livraison,
 * aux jours et horaires du contrat (en dehors, la course suit le circuit habituel).
 * Pour la livraison, celui qui a fait le ramassage d'abord ; sinon le moins chargé des livreurs disponibles
 * (en service, compte actif, pas absent ni écarté pour cette course).
 * Contrat « réservé » (réglage) : ses livreurs ne reçoivent pas les courses des autres clients pendant la MAD.
 */
class MadCouriers
{
    public function __construct(
        private readonly CourierPlanning $planning,
        private readonly ActivityOptions $options,
    ) {}

    /**
     * @param  list<int>  $exclude  livreurs écartés pour cette course (refus, empêchement, injoignable)
     */
    public function choose(Order $order, AssignmentType $type, array $exclude = []): ?Courier
    {
        if ($order->billing_plan_id === null) {
            return null;
        }
        $plan = BillingPlan::withoutGlobalScopes()->with(['couriers.user.company', 'couriers.activeAssignments'])->find($order->billing_plan_id);
        if ($plan === null || $plan->formula !== BillingFormula::MadContract || $plan->couriers->isEmpty()) {
            return null;
        }

        $company = $order->company;
        $now = $this->now($company);
        if (! $this->options->reservedAt($this->options->plan($plan, $company), $now)) {
            return null;
        }
        $absent = [...$this->planning->absentOn($order->company_id, $now->toDateString()), ...$exclude];
        $available = $plan->couriers->filter(fn (Courier $c) => $this->planning->available($c, $absent));

        if ($type !== AssignmentType::Pickup && ($picker = $available->firstWhere('id', $order->pickup_courier_id))) {
            return $picker;
        }

        return $available->sortBy([[fn (Courier $c) => $c->activeAssignments->count(), 'asc'], ['id', 'asc']])->first();
    }

    /**
     * Livreurs réservés en ce moment à un autre client que celui de la course (contrats MAD réservés) :
     * l'assignation automatique ne les lui donne pas.
     *
     * @return list<int>
     */
    public function reservedElsewhere(Order $order): array
    {
        return collect($this->reservations($order->company_id))
            ->filter(fn (array $r) => $r['reserved_now'] && $r['exclusive'] && $r['billing_plan_id'] !== $order->billing_plan_id)
            ->keys()->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Livreurs en MAD aujourd'hui, par livreur : contrat, client, jusqu'à quand, réservé en ce moment ou non.
     *
     * @return array<int, array{billing_plan_id: int, merchant_id: int, merchant: string|null, ends_on: string|null, days: list<int>, from: string|null, to: string|null, exclusive: bool, reserved_now: bool}>
     */
    public function reservations(int $companyId, ?CarbonImmutable $at = null): array
    {
        $company = Company::query()->find($companyId);
        $at ??= $this->now($company);
        $out = [];

        BillingPlan::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('formula', BillingFormula::MadContract->value)->activeOn($at->toDateString())
            ->with(['couriers:id', 'merchant:id,business_name'])->orderBy('starts_on')->get()
            ->each(function (BillingPlan $plan) use ($company, $at, &$out) {
                $options = $this->options->plan($plan, $company);
                foreach ($plan->couriers as $courier) {
                    $out[$courier->id] ??= [
                        'billing_plan_id' => $plan->id,
                        'merchant_id' => $plan->merchant_id,
                        'merchant' => $plan->merchant?->business_name,
                        'ends_on' => $plan->ends_on?->toDateString(),
                        'days' => array_map('intval', $options['mad_days']),
                        'from' => $options['mad_from'],
                        'to' => $options['mad_to'],
                        'exclusive' => (bool) $options['mad_exclusive'],
                        'reserved_now' => $this->options->reservedAt($options, $at),
                    ];
                }
            });

        return $out;
    }

    /**
     * MAD du livreur aujourd'hui (pastille « MAD · client » des listes), ou null.
     * Gardé une minute par entreprise : les listes de livreurs ne relisent pas les contrats pour chacun.
     *
     * @return array<string, mixed>|null
     */
    public function of(Courier $courier): ?array
    {
        $key = $courier->company_id.'@'.now()->format('YmdHi');
        self::$memo[$key] ??= $this->reservations($courier->company_id);

        return self::$memo[$key][$courier->id] ?? null;
    }

    /** @var array<string, array<int, array<string, mixed>>> */
    private static array $memo = [];

    private function now(?Company $company): CarbonImmutable
    {
        return CarbonImmutable::now($company?->timezone ?: config('app.timezone'));
    }
}
