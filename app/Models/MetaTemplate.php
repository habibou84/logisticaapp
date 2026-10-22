<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * État d'approbation, chez Meta, d'un modèle du catalogue (App\Enums\WhatsAppTemplate).
 */
class MetaTemplate extends Model
{
    protected $table = 'whatsapp_templates';

    protected $fillable = ['whatsapp_account_id', 'name', 'language', 'category', 'status', 'rejected_reason', 'synced_at'];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }
}
