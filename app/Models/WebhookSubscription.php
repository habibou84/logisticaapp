<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Adresse à laquelle un marchand reçoit les événements (courses, reversements, stock),
 * signés HMAC-SHA256 avec un secret qui lui est propre.
 */
class WebhookSubscription extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'merchant_id', 'url', 'events', 'secret', 'description', 'is_active', 'created_by'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'secret' => 'encrypted',
            'is_active' => 'boolean',
            'consecutive_failures' => 'integer',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function listensTo(string $event): bool
    {
        return $this->is_active && in_array($event, $this->events ?? [], true);
    }
}
