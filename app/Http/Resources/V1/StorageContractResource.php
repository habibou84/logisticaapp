<?php

namespace App\Http\Resources\V1;

use App\Models\StorageCharge;
use App\Models\StorageContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StorageContract
 */
class StorageContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'merchant' => $this->whenLoaded('merchant', fn () => [
                'id' => $this->merchant->id,
                'business_name' => $this->merchant->business_name,
            ]),
            'hub_id' => $this->hub_id,
            'hub_name' => $this->whenLoaded('hub', fn () => $this->hub?->name),
            'billing_type' => $this->billing_type,
            'billing_type_label' => $this->billing_type->label(),
            'price' => $this->price,
            'description' => $this->describe(),
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'is_active' => $this->starts_on->lte(today()) && ($this->ends_on === null || $this->ends_on->gte(today())),
            'notes' => $this->notes,
            'charges' => $this->whenLoaded('charges', fn () => $this->charges->map(fn (StorageCharge $c) => [
                'id' => $c->id,
                'period' => $c->period->toDateString(),
                'quantity' => $c->quantity,
                'amount' => $c->amount,
            ])),
        ];
    }
}
