<?php

namespace App\Models;

use App\Enums\SurchargeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingSurcharge extends Model
{
    protected $fillable = ['pricing_grid_id', 'type', 'min_value', 'max_value', 'amount', 'percent'];

    protected function casts(): array
    {
        return [
            'type' => SurchargeType::class,
            'min_value' => 'float',
            'max_value' => 'float',
            'amount' => 'integer',
            'percent' => 'float',
        ];
    }

    public function grid(): BelongsTo
    {
        return $this->belongsTo(PricingGrid::class, 'pricing_grid_id');
    }

    /**
     * Montant du supplément pour un tarif de base donné.
     */
    public function amountFor(int $basePrice): int
    {
        return $this->amount + (int) round($basePrice * ($this->percent ?? 0) / 100);
    }
}
