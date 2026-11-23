<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Message reçu sur le numéro WhatsApp de l'entreprise.
 */
class InboundMessage extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'whatsapp_account_id', 'provider_message_id', 'from_phone', 'merchant_id',
        'type', 'body', 'payload', 'order_id', 'processed_at', 'read_at', 'read_by', 'simulated',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'simulated' => 'boolean',
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
