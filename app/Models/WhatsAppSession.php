<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conversation WhatsApp en cours : brouillon de course et question en attente.
 */
class WhatsAppSession extends Model
{
    use BelongsToCompany;

    protected $table = 'whatsapp_sessions';

    protected $fillable = ['company_id', 'phone', 'merchant_id', 'user_id', 'state', 'draft', 'awaiting', 'expires_at'];

    protected $attributes = ['state' => 'idle'];

    protected function casts(): array
    {
        return [
            'draft' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reset(): void
    {
        $this->forceFill(['state' => 'idle', 'draft' => null, 'awaiting' => null]);
    }
}
