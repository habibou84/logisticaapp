<?php

namespace App\Http\Resources\V1;

use App\Models\Zone;
use App\Services\Zones\PlaceList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Zone
 */
class ZoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            // Code du lieu de la liste commune du socle (null : zone propre à l'entreprise, ou ville pas encore dans
            // la liste commune). Contrat 1.7
            'place' => PlaceList::isCommon($this->place_code) ? $this->place_code : null,
            // Lieu de la liste des lieux de l'application (nom fixé) ; hors de l'API publique
            'is_standard' => $this->when(! $request->is('api/public/*'), fn () => $this->place_code !== null),
            'name' => $this->name,
            'full_name' => $this->relationLoaded('parent') ? $this->fullName() : $this->name,
            'city' => $this->city,
            'is_shipping' => $this->is_shipping,
            'in_forfait' => (bool) $this->in_forfait,
            // Collecte possible ici (quartier : et dans sa commune). Hors de l'API publique : pas au contrat
            'is_pickup' => $this->when(! $request->is('api/public/*'), fn () => $this->acceptsPickup()),
            'shipping_fee_estimate' => $this->shipping_fee_estimate,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
