<?php

namespace App\Http\Resources\V1;

use App\Models\Courier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Courier
 */
class CourierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->user?->name,
            'phone' => $this->user?->phone,
            'status' => $this->user?->status,
            'vehicle_type' => $this->vehicle_type,
            'vehicle_plate' => $this->vehicle_plate,
            'pay_plan_id' => $this->pay_plan_id,
            'pay_plan' => $this->whenLoaded('payPlan', fn () => $this->payPlan ? [
                'id' => $this->payPlan->id,
                'name' => $this->payPlan->name,
                'personal' => $this->payPlan->courier_id !== null,
            ] : null),
            'is_available' => $this->is_available,
            // Exception à la règle d'acceptation de l'entreprise (null : celle de l'entreprise)
            'mission_acceptance' => $this->mission_acceptance,
            'current_lat' => $this->current_lat,
            'current_lng' => $this->current_lng,
            'last_location_at' => $this->last_location_at,
            // Zones principales ; les zones de secours à part
            'zones' => ZoneResource::collection($this->whenLoaded('zones', fn () => $this->zones->reject(fn ($z) => (bool) $z->pivot?->is_backup)->values())),
            'backup_zones' => ZoneResource::collection($this->whenLoaded('zones', fn () => $this->zones->filter(fn ($z) => (bool) $z->pivot?->is_backup)->values())),
            'max_parcels' => $this->max_parcels,
            'max_cash' => $this->max_cash,
            'shift_ends_at' => $this->shift_ends_at,
            'active_assignments_count' => $this->whenCounted('activeAssignments'),
            'notes' => $this->notes,
        ];
    }
}
