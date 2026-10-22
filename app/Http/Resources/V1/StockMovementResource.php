<?php

namespace App\Http\Resources\V1;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => $this->type->label(),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
                'merchant_id' => $this->product->merchant_id,
                'merchant_name' => $this->product->relationLoaded('merchant') ? $this->product->merchant?->business_name : null,
            ]),
            'location' => $this->whenLoaded('location', fn () => [
                'id' => $this->location->id,
                'hub_id' => $this->location->hub_id,
                'label' => $this->location->label(),
            ]),
            'on_hand_change' => $this->on_hand_change,
            'reserved_change' => $this->reserved_change,
            'on_hand_after' => $this->on_hand_after,
            'order' => $this->whenLoaded('order', fn () => $this->order ? [
                'id' => $this->order->id,
                'tracking_code' => $this->order->tracking_code,
            ] : null),
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'note' => $this->note,
            'created_at' => $this->created_at,
        ];
    }
}
