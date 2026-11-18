<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Photo d'une intervention : avant réparation, après réparation, ou facture d'un achat sur place.
 */
class RepairFile extends Model
{
    public const UPDATED_AT = null;

    public const KINDS = ['before', 'after', 'receipt'];

    protected $fillable = ['repair_id', 'kind', 'disk', 'path', 'mime_type'];

    public function repair(): BelongsTo
    {
        return $this->belongsTo(Repair::class);
    }
}
