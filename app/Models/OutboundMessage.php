<?php

namespace App\Models;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Journal des messages envoyés (WhatsApp, SMS) et de leur remise.
 */
class OutboundMessage extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'channel', 'whatsapp_account_id', 'to', 'recipient_type', 'event', 'template_name',
        'payload', 'body', 'order_id', 'merchant_id', 'fallback_for_id', 'status', 'provider_message_id',
        'error', 'attempts', 'sent_at', 'delivered_at', 'read_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'status' => MessageStatus::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function fallbackFor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fallback_for_id');
    }

    public function fallback(): HasOne
    {
        return $this->hasOne(self::class, 'fallback_for_id');
    }

    /**
     * Applique un statut de remise sans jamais revenir en arrière.
     */
    public function advanceTo(MessageStatus $status, ?string $error = null): bool
    {
        if ($this->status === MessageStatus::Failed || $status->rank() <= $this->status->rank()) {
            return false;
        }

        $this->status = $status;
        $column = match ($status) {
            MessageStatus::Sent => 'sent_at',
            MessageStatus::Delivered => 'delivered_at',
            MessageStatus::Read => 'read_at',
            MessageStatus::Failed => 'failed_at',
            default => null,
        };
        if ($column) {
            $this->{$column} ??= now();
        }
        if ($error !== null) {
            $this->error = $error;
        }

        return $this->save();
    }
}
