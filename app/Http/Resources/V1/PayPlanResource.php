<?php

namespace App\Http\Resources\V1;

use App\Models\PayPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PayPlan
 */
class PayPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_default' => $this->is_default,
            'personal' => $this->courier_id !== null,
            'courier' => $this->when($this->courier_id !== null && $this->relationLoaded('owner'), fn () => [
                'id' => $this->owner?->id,
                'name' => $this->owner?->user?->name,
            ]),
            'pickup_mode' => $this->pickup_mode,
            'pickup_extra_parcel_amount' => $this->pickup_extra_parcel_amount,
            'min_amount' => $this->min_amount,
            'max_amount' => $this->max_amount,
            'base_salary' => $this->base_salary,
            'pay_period' => $this->pay_period,
            'pay_period_label' => $this->periodLabel(),
            'deduction_cap_percent' => $this->deduction_cap_percent,
            'couriers_count' => $this->whenCounted('couriers'),
            'bonuses' => $this->whenLoaded('bonuses', fn () => $this->bonuses->map(fn ($b) => [
                'id' => $b->id,
                'metric' => $b->metric->value,
                'threshold' => $b->threshold,
                'min_count' => $b->min_count,
                'amount' => $b->amount,
                'label' => $b->label,
            ])->values()),
            'rules' => $this->whenLoaded('rules', fn () => $this->rules->map(fn ($rule) => [
                'id' => $rule->id,
                'event' => $rule->event->value,
                'event_label' => $rule->event->label(),
                'calc' => $rule->calc->value,
                'amount' => $rule->amount,
                'percent' => $rule->percent,
                'zone_amounts' => (object) ($rule->zone_amounts ?? []),
                'conditions' => (object) ($rule->conditions ?? []),
                'label' => $rule->label,
            ])->values()),
        ];
    }
}
