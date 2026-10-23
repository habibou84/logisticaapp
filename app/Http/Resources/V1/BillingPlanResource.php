<?php

namespace App\Http\Resources\V1;

use App\Models\BillingPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BillingPlan
 */
class BillingPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'formula' => $this->formula->value,
            'formula_label' => $this->formula->label(),
            'has_forfait' => $this->formula->hasForfait(),
            'monthly_fee' => $this->monthly_fee,
            'included_quantity' => $this->included_quantity,
            'overage_price' => $this->overage_price,
            'delivery_delay' => $this->delivery_delay->value,
            'cheque_price' => $this->cheque_price,
            'extra_kg_price' => $this->extra_kg_price,
            'interior_in_forfait' => $this->resource->countsInterior(),
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'notes' => $this->when($request->user()?->merchant_id === null, $this->notes),
            'summary' => $this->describe(),
            'couriers' => $this->whenLoaded('couriers', fn () => $this->couriers->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->user?->name,
            ])->values()),
        ];
    }
}
