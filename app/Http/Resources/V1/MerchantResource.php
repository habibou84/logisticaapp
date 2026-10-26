<?php

namespace App\Http\Resources\V1;

use App\Enums\Activity;
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
            // Identité fiscale (factures) et exonération de TVA
            'legal_name' => $this->legal_name,
            'ncc' => $this->ncc,
            'rccm' => $this->rccm,
            'billing_address' => $this->billing_address,
            'vat_exempt' => (bool) $this->vat_exempt,
            'vat_exemption_ref' => $this->vat_exemption_ref,
            'vat_exemption_until' => $this->vat_exemption_until?->toDateString(),
            'vat_exemption_type' => $this->vat_exemption_type ?? 'conventional',
            'fne_template' => $this->fne_template,
            // Étiquettes : réglage propre au client (vide : celui de l'entreprise)
            'label_settings' => $this->label_settings ?: null,
            'orders_count' => $this->whenCounted('orders'),
            // Activités du client (e-commerce, distribution, MAD) et formules en cours (une par activité facturée)
            'activities' => array_map(fn (Activity $a) => $a->value, $this->resource->activityList()),
            'billing_plans' => $this->whenLoaded('billingPlans', fn () => $this->billingPlans->reject(fn ($p) => $p->formula->isLot())
                ->unique(fn ($p) => $p->activity->value)->map(fn ($p) => [...$p->brief(), ...($isStaff ? $p->operations() : [])])->values()),
            // Lots ponctuels ouverts (distribution) : choisis sur la course
            'lots' => $this->whenLoaded('billingPlans', fn () => $this->billingPlans->filter(fn ($p) => $p->formula->isLot())
                ->map(fn ($p) => [...$p->brief(), ...($isStaff ? $p->operations() : [])])->values()),
            'users' => UserResource::collection($this->whenLoaded('users')),
            'created_at' => $this->created_at,
        ];
    }
}
