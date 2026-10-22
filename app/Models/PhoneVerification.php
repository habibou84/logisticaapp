<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vérification d'un numéro de téléphone : inscription d'un e-commerçant ou mot
 * de passe oublié. Le numéro est prouvé par un message WhatsApp envoyé depuis ce
 * numéro (code prérempli) ou par le code reçu par SMS.
 */
class PhoneVerification extends Model
{
    public const SIGNUP = 'signup';

    public const PASSWORD_RESET = 'password_reset';

    protected $fillable = [
        'company_id', 'purpose', 'phone', 'token_hash', 'whatsapp_code', 'sms_code_hash',
        'sms_count', 'sms_sent_at', 'attempts', 'user_id', 'payload', 'ip',
        'verified_via', 'verified_at', 'completed_at', 'expires_at',
    ];

    protected $hidden = ['token_hash', 'sms_code_hash', 'payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'sms_count' => 'integer',
            'attempts' => 'integer',
            'sms_sent_at' => 'datetime',
            'verified_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Encore utilisable : ni expirée, ni déjà menée à son terme.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('completed_at')->where('expires_at', '>', now());
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
