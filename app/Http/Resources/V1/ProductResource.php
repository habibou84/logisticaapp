<?php

namespace App\Http\Resources\V1;

use App\Models\Product;
use App\Models\StockLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $levels = $this->whenLoaded('levels', fn () => $this->levels
            ->sortBy(fn (StockLevel $l) => [$l->location->hub_id === null ? 0 : 1, $l->location->hub?->name])
            ->values()
            ->map(fn (StockLevel $l) => [
                'location_id' => $l->stock_location_id,
                'hub_id' => $l->location->hub_id,
                'label' => $l->location->label(),
                'on_hand' => $l->on_hand,
                'reserved' => $l->reserved,
                'available' => $l->available(),
            ]));

        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'merchant' => $this->whenLoaded('merchant', fn () => [
                'id' => $this->merchant->id,
                'business_name' => $this->merchant->business_name,
            ]),
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'photo_url' => $this->photoUrl(),
            'shop_visible' => $this->shop_visible,
            'price' => $this->price,
            'weight_kg' => $this->weight_kg,
            'low_stock_threshold' => $this->low_stock_threshold,
            'is_active' => $this->is_active,
            'levels' => $levels,
            'on_hand' => $this->whenLoaded('levels', fn () => (int) $this->levels->sum('on_hand')),
            'reserved' => $this->whenLoaded('levels', fn () => (int) $this->levels->sum('reserved')),
            'available' => $this->whenLoaded('levels', fn () => $this->available()),
            'is_low' => $this->whenLoaded('levels', fn () => $this->isLow()),
            'created_at' => $this->created_at,
        ];
    }
}
