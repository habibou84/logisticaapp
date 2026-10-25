<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Position relevée sur place pour une destination (par un livreur ou l'équipe) et ce qu'elle a donné.
 */
class DestinationLocation extends Model
{
    public const OUTCOMES = ['kept', 'replaced', 'conflict', 'rejected'];

    protected $fillable = ['destination_id', 'user_id', 'order_id', 'lat', 'lng', 'accuracy', 'distance', 'outcome'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'accuracy' => 'integer', 'distance' => 'integer'];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
