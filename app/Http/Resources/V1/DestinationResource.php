<?php

namespace App\Http\Resources\V1;

use App\Models\Destination;
use App\Services\Destinations\DestinationPhones;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Destination
 */
class DestinationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->merchant_id === null;
        $raw = DestinationPhones::parse($this->phones_raw)['doubtful'];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'acronym' => $this->acronym,
            'label' => $this->label(),
            'city' => $this->city,
            'zone_id' => $this->zone_id,
            'zone_name' => $this->whenLoaded('zone', fn () => $this->zone?->name),
            'district' => $this->district,
            'address' => $this->address,
            'landmark' => $this->landmark,
            'phone' => $this->phone,
            'phone2' => $this->phone2,
            'phone3' => $this->phone3,
            'phones' => array_map(fn ($p) => PhoneNumber::display($p), $this->phones()),
            // Numéros d'origine « à vérifier », et la correction proposée pour chacun (jamais appliquée seule)
            'phone_check' => $this->phone_check,
            'phones_raw' => $this->phones_raw,
            'doubtful' => $this->phone_check ? array_map(fn ($d) => ['raw' => $d, 'suggestion' => ($s = DestinationPhones::suggestion($d)) ? PhoneNumber::display($s) : null], $raw) : [],
            'email' => $this->email,
            'sector' => $this->sector,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'location_accuracy' => $this->location_accuracy,
            'location_source' => $this->location_source,
            'located_at' => $this->located_at,
            'located_by' => $this->whenLoaded('locator', fn () => $this->locator?->name),
            'location_conflict' => $this->when($staff, $this->location_conflict),
            'deliveries_count' => $this->deliveries_count,
            'last_used_at' => $this->last_used_at,
            'source' => $this->when($staff, $this->source),
            'status' => $this->status,
            // Déjà livrée pour ce client (suggestions)
            'mine' => $this->when(isset($this->mine), fn () => (int) $this->mine),
            'locations' => $this->when($staff && $this->relationLoaded('locations'), fn () => $this->locations->map(fn ($l) => [
                'id' => $l->id, 'lat' => $l->lat, 'lng' => $l->lng, 'accuracy' => $l->accuracy, 'distance' => $l->distance,
                'outcome' => $l->outcome, 'by' => $l->user?->name, 'at' => $l->created_at,
            ])->values()),
            'created_at' => $this->created_at,
        ];
    }
}
