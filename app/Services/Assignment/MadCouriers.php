<?php

namespace App\Services\Assignment;

use App\Enums\AssignmentType;
use App\Enums\BillingFormula;
use App\Models\BillingPlan;
use App\Models\Courier;
use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * Mise à disposition (MAD) : les courses du client vont à ses livreurs attitrés, ramassage et livraison.
 * Pour la livraison, celui qui a fait le ramassage d'abord ; sinon le moins chargé des livreurs disponibles
 * (en service, compte actif, pas absent ni écarté pour cette course).
 */
class MadCouriers
{
    public function __construct(private readonly CourierPlanning $planning) {}

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
        $today = CarbonImmutable::now($company?->timezone ?: config('app.timezone'))->toDateString();
        $absent = [...$this->planning->absentOn($order->company_id, $today), ...$exclude];
        $available = $plan->couriers->filter(fn (Courier $c) => $this->planning->available($c, $absent));

        if ($type !== AssignmentType::Pickup && ($picker = $available->firstWhere('id', $order->pickup_courier_id))) {
            return $picker;
        }

        return $available->sortBy([[fn (Courier $c) => $c->activeAssignments->count(), 'asc'], ['id', 'asc']])->first();
    }
}
