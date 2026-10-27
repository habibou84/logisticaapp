<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAttachment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'event_id', 'type', 'disk', 'path', 'original_path', 'meta', 'client_ref', 'mime_type', 'uploaded_by'];

    /** Informations de la prise : rendu du scanner, qualité, heure et position */
    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(OrderEvent::class, 'event_id');
    }
}
