<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Remise au client des colis qu'un livreur lui rapporte, confirmée par le client (WhatsApp ou application),
 * ou faite « de secours » (signature ou photo, motif) et vérifiée par l'agence.
 */
class ReturnHandover extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const UNCONFIRMED = 'unconfirmed';

    public const DISPUTED = 'disputed';

    public const RESOLVED = 'resolved';

    public const CANCELLED = 'cancelled';

    /** Durée de validité du QR code affiché par le livreur */
    public const VALID_MINUTES = 15;

    public const FALLBACK_REASONS = [
        'no_phone' => 'Pas de téléphone ou pas de réseau',
        'employee' => 'Remis à un employé sans WhatsApp',
        'other' => 'Autre',
    ];

    protected $fillable = [
        'company_id', 'merchant_id', 'courier_id', 'reference', 'status', 'order_ids', 'expires_at',
        'confirmed_at', 'confirmed_via', 'confirmed_phone', 'fallback_reason', 'received_by_name',
        'signature_path', 'photo_path', 'lat', 'lng', 'handed_at', 'disputed_at', 'resolved_at', 'resolved_by', 'resolution',
    ];

    protected function casts(): array
    {
        return [
            'order_ids' => 'array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'handed_at' => 'datetime',
            'disputed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    /** @return Collection<int, Order> */
    public function orders(): Collection
    {
        return Order::query()->whereIn('id', $this->order_ids ?? [])->where('company_id', $this->company_id)->orderBy('id')->get();
    }

    public function isExpired(): bool
    {
        return $this->status === self::PENDING && $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Remise de secours ou contestation : l'agence doit trancher */
    public function needsReview(): bool
    {
        return in_array($this->status, [self::UNCONFIRMED, self::DISPUTED], true);
    }

    /** Texte du message préparé que le client envoie après le scan */
    public function message(): string
    {
        $count = count($this->order_ids ?? []);

        return "RETOUR {$this->reference} : j'ai bien reçu ".($count > 1 ? "les {$count} colis" : 'le colis');
    }
}
