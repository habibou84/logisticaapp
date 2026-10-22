<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bon de retour : colis d'un marchand rapportés ensemble par un livreur.
 */
class ReturnSlip extends Model
{
    use BelongsToCompany;

    public const OPEN = 'open';

    public const HANDED = 'handed';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'merchant_id', 'courier_id', 'reference', 'status', 'created_by',
        'handed_at', 'received_by_name', 'signature_path', 'photo_path', 'lat', 'lng', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'handed_at' => 'datetime',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::OPEN => 'En cours',
            self::HANDED => 'Remis au marchand',
            self::CANCELLED => 'Annulé',
            default => $this->status,
        };
    }
}
