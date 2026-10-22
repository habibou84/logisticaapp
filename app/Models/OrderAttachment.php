<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAttachment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'event_id', 'type', 'disk', 'path', 'mime_type', 'uploaded_by'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(OrderEvent::class, 'event_id');
    }
}
