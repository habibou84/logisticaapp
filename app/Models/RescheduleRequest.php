<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Demande de nouvelle date à un client pour ses colis pas ramassés à leur date (message du soir ou dispatch).
 * Réponse par WhatsApp, par la page de réponse (lien personnel) ou par Jibiat Marchand.
 */
class RescheduleRequest extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';

    public const ANSWERED = 'answered';

    // Plus rien à reporter (colis ramassés, annulés ou datés autrement) ou lien périmé
    public const CLOSED = 'closed';

    public const EVENING = 'evening';

    public const DISPATCH = 'dispatch';

    /** Durée de validité du lien de réponse */
    public const TTL_DAYS = 3;

    protected $fillable = [
        'company_id', 'merchant_id', 'token_hash', 'token', 'order_ids', 'missed_day', 'origin', 'status', 'note', 'created_by',
        'whatsapp_sent_at', 'reminded_at', 'answered_at', 'answered_via', 'expires_at',
    ];

    protected $hidden = ['token', 'token_hash'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'order_ids' => 'array',
            'missed_day' => 'date',
            'whatsapp_sent_at' => 'datetime',
            'reminded_at' => 'datetime',
            'answered_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function newToken(): string
    {
        return Str::random(32);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Courses encore rattachées à la demande */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::PENDING)->where('expires_at', '>', now());
    }

    public function isOpen(): bool
    {
        return $this->status === self::PENDING && $this->expires_at->isFuture();
    }
}
