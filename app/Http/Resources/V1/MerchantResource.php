<?php

namespace App\Http\Resources\V1;

use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Merchant
 */
class MerchantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isStaff = $request->user()?->merchant_id === null;

        return [
            'id' => $this->id,
            'business_name' => $this->business_name,
            'contact_name' => $this->contact_name,
            'phone' => $this->phone,
            'whatsapp_phone' => $this->whatsapp_phone,
            'email' => $this->email,
            'pickup_zone_id' => $this->pickup_zone_id,
            'pickup_zone' => ZoneResource::make($this->whenLoaded('pickupZone')),
            'pickup_address' => $this->pickup_address,
            'pickup_landmark' => $this->pickup_landmark,
            'pickup_lat' => $this->pickup_lat,
            'pickup_lng' => $this->pickup_lng,
            // merchant (sur place), staff (agence) ou courier (estimée d'après les ramassages)
            'pickup_location_source' => $this->pickup_location_source,
            'pickup_located_at' => $this->pickup_located_at,
            'pickup_location_accuracy' => $this->pickup_location_accuracy,
            'default_fee_payer' => $this->default_fee_payer,
            'status' => $this->status,
            'source' => $this->source,
            // Informations internes à l'entreprise de livraison
            'pricing_grid_id' => $this->when($isStaff, $this->pricing_grid_id),
            'notes' => $this->when($isStaff, $this->notes),
            'orders_count' => $this->whenCounted('orders'),
            'users' => UserResource::collection($this->whenLoaded('users')),
            'created_at' => $this->created_at,
        ];
    }
}
