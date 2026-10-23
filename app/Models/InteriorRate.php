<?php

namespace App\Models;

use App\Enums\DeliveryDelay;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Tarif d'une ville de l'intérieur pour un délai : pli (0 à 500 g), colis (0 à 5 kg) et chaque
 * kilogramme au-delà de 5 kg. La ville correspond à la ville des zones de livraison.
 */
class InteriorRate extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'merchant_id', 'city', 'delivery_delay', 'pli_price', 'colis_price', 'extra_kg_price'];

    /**
     * Grille du client : ses prix négociés, ou la grille commune (merchant_id vide) quand il n'en a pas.
     */
    public function scopeGrid($query, ?int $merchantId)
    {
        return $merchantId === null ? $query->whereNull('merchant_id') : $query->where('merchant_id', $merchantId);
    }

    protected function casts(): array
    {
        return [
            'delivery_delay' => DeliveryDelay::class,
            'pli_price' => 'integer',
            'colis_price' => 'integer',
            'extra_kg_price' => 'integer',
        ];
    }
}
