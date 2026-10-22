<?php

namespace App\Http\Resources\V1;

use App\Models\Hub;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Hub
 */
class HubResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'zone_id' => $this->zone_id,
            'zone_name' => $this->whenLoaded('zone', fn () => $this->zone?->fullName()),
            'address' => $this->address,
            'landmark' => $this->landmark,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
        ];
    }
}
