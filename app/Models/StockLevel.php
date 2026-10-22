<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quantités d'un produit à un emplacement (cache recalculable depuis les mouvements).
 */
class StockLevel extends Model
{
    protected $fillable = ['product_id', 'stock_location_id', 'on_hand', 'reserved'];

    protected function casts(): array
    {
        return ['on_hand' => 'integer', 'reserved' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    public function available(): int
    {
        return $this->on_hand - $this->reserved;
    }
}
