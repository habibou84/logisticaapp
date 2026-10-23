<?php

namespace App\Models;

use App\Enums\DeliveryDelay;
use App\Enums\ShipmentType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Distribution à l'unité sur Abidjan : prix d'un pli, d'un colis ou d'un chèque selon le délai.
 */
class DistributionRate extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'merchant_id', 'delivery_delay', 'shipment_type', 'price'];

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
            'shipment_type' => ShipmentType::class,
            'price' => 'integer',
        ];
    }
}
