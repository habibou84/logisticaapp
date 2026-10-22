<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un envoi d'événement à une adresse webhook, avec ses tentatives.
 */
class WebhookDelivery extends Model
{
    use BelongsToCompany, MassPrunable;

    protected $fillable = [
        'company_id', 'webhook_subscription_id', 'event_id', 'event', 'payload', 'status', 'attempts',
        'response_code', 'response_body', 'error', 'next_retry_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'response_code' => 'integer',
            'next_retry_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * Journal conservé 30 jours.
     */
    public function prunable()
    {
        return static::withoutGlobalScopes()->where('created_at', '<', now()->subDays(30));
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'webhook_subscription_id');
    }
}
