<?php

namespace App\Http\Resources\V1;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Vehicle
 */
class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'plate' => $this->plate,
            'brand' => $this->brand,
            'model' => $this->model,
            'year' => $this->year,
            'color' => $this->color,
            'vin' => $this->vin,
            'capacity_parcels' => $this->capacity_parcels,
            'capacity_kg' => $this->capacity_kg,
            'has_box' => $this->has_box,
            'ownership' => $this->ownership,
            'ownership_label' => Vehicle::OWNERSHIPS[$this->ownership] ?? $this->ownership,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? ['id' => $this->owner->id, 'name' => $this->owner->user?->name] : null),
            'status' => $this->status,
            'status_label' => Vehicle::STATUSES[$this->status] ?? $this->status,
            'courier' => $this->whenLoaded('courier', fn () => $this->courier ? ['id' => $this->courier->id, 'name' => $this->courier->user?->name, 'phone' => $this->courier->user?->phone] : null),
            'odometer' => $this->odometer,
            'odometer_at' => $this->odometer_at,
            'purchase_price' => $this->purchase_price,
            'purchased_on' => $this->purchased_on?->toDateString(),
            'maintenance_plan' => $this->maintenance_plan ?? [],
            'field_event_id' => $this->field_event_id,
            'notes' => $this->notes,
            'has_photo' => $this->photo_path !== null,
            'created_at' => $this->created_at,
        ];
    }

    public const RELATIONS = ['courier.user', 'owner.user'];
}
