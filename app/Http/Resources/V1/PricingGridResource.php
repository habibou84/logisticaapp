<?php

namespace App\Http\Resources\V1;

use App\Models\PricingGrid;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PricingGrid
 */
class PricingGridResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_default' => $this->is_default,
            'rules_count' => $this->whenCounted('rules'),
            'merchants_count' => $this->whenCounted('merchants'),
            'rules' => $this->whenLoaded('rules', fn () => $this->rules->map(fn ($rule) => [
                'id' => $rule->id,
                'origin_zone_id' => $rule->origin_zone_id,
                'destination_zone_id' => $rule->destination_zone_id,
                'price' => $rule->price,
                'is_symmetric' => $rule->is_symmetric,
            ])),
            'surcharges' => $this->whenLoaded('surcharges', fn () => $this->surcharges->map(fn ($s) => [
                'id' => $s->id,
                'type' => $s->type,
                'label' => $s->type->label(),
                'min_value' => $s->min_value,
                'max_value' => $s->max_value,
                'amount' => $s->amount,
                'percent' => $s->percent,
            ])),
            'updated_at' => $this->updated_at,
        ];
    }
}
