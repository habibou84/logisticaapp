<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Mouvement de stock : journal immuable (une erreur se corrige par un inventaire).
 */
class StockMovement extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'product_id', 'stock_location_id', 'type', 'on_hand_change', 'reserved_change',
        'on_hand_after', 'order_id', 'user_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'on_hand_change' => 'integer',
            'reserved_change' => 'integer',
            'on_hand_after' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Un mouvement de stock ne peut pas être modifié.'));
        static::deleting(fn () => throw new LogicException('Un mouvement de stock ne peut pas être supprimé.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
