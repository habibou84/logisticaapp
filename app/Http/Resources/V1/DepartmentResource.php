<?php

namespace App\Http\Resources\V1;

use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Department
 */
class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'name' => $this->name,
            'code' => $this->code,
            'label' => $this->label(),
            'manager_name' => $this->manager_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'location' => $this->location,
            // Autre site que le client : son adresse
            'own_address' => $this->hasOwnAddress(),
            'pickup_zone_id' => $this->pickup_zone_id,
            'pickup_zone_name' => $this->whenLoaded('pickupZone', fn () => $this->pickupZone?->name),
            'pickup_address' => $this->pickup_address,
            'pickup_landmark' => $this->pickup_landmark,
            'pickup_lat' => $this->pickup_lat,
            'pickup_lng' => $this->pickup_lng,
            'cost_center' => $this->cost_center,
            'monthly_quota' => $this->monthly_quota,
            'is_main' => $this->is_main,
            'active' => $this->active,
            'position' => $this->position,
            'notes' => $this->when($request->user()?->merchant_id === null, $this->notes),
            'user_ids' => $this->whenLoaded('users', fn () => $this->users->modelKeys()),
            // Consommation du mois (quota)
            'usage' => $this->when(isset($this->usage), fn () => $this->usage),
            'orders_count' => $this->whenCounted('orders'),
        ];
    }
}
