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

    protected $fillable = ['company_id', 'delivery_delay', 'shipment_type', 'price'];

    protected function casts(): array
    {
        return [
            'delivery_delay' => DeliveryDelay::class,
            'shipment_type' => ShipmentType::class,
            'price' => 'integer',
        ];
    }
}
