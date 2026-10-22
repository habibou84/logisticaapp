<?php

namespace App\Http\Resources\V1;

use App\Models\Recipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Recipient
 */
class RecipientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'name' => $this->name,
            'phone' => $this->phone,
            'phone2' => $this->phone2,
            'zone_id' => $this->zone_id,
            'address' => $this->address,
            'landmark' => $this->landmark,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'deliveries_count' => $this->deliveries_count,
            'failed_count' => $this->failed_count,
        ];
    }
}
