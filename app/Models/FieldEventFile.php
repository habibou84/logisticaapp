<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Photo ou note vocale jointe à un événement terrain.
 */
class FieldEventFile extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['field_event_id', 'kind', 'disk', 'path', 'mime_type', 'duration'];

    protected function casts(): array
    {
        return ['duration' => 'integer'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(FieldEvent::class, 'field_event_id');
    }
}
