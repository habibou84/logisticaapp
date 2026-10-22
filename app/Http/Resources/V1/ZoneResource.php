<?php

namespace App\Http\Resources\V1;

use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Zone
 */
class ZoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'full_name' => $this->relationLoaded('parent') ? $this->fullName() : $this->name,
            'city' => $this->city,
            'is_shipping' => $this->is_shipping,
            'shipping_fee_estimate' => $this->shipping_fee_estimate,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
