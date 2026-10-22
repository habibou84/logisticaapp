<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingRule extends Model
{
    protected $fillable = ['pricing_grid_id', 'origin_zone_id', 'destination_zone_id', 'price', 'is_symmetric'];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'is_symmetric' => 'boolean',
        ];
    }

    public function grid(): BelongsTo
    {
        return $this->belongsTo(PricingGrid::class, 'pricing_grid_id');
    }

    public function originZone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'origin_zone_id');
    }

    public function destinationZone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'destination_zone_id');
    }
}
