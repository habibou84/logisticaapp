<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Intervention d'un mécanicien sur une panne : cherchée (attribution automatique), proposée à un mécanicien,
 * acceptée, en route, sur place, en réparation, terminée (ou annulée). Chaque étape garde son heure.
 */
class Repair extends Model
{
    use BelongsToCompany;

    public const SEARCHING = 'searching';

    public const OFFERED = 'offered';

    public const ACCEPTED = 'accepted';

    public const EN_ROUTE = 'en_route';

    public const ON_SITE = 'on_site';

    public const REPAIRING = 'repairing';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    /** En attente d'un mécanicien */
    public const WAITING = [self::SEARCHING, self::OFFERED];

    /** Prise en charge par un mécanicien, pas terminée */
    public const IN_PROGRESS = [self::ACCEPTED, self::EN_ROUTE, self::ON_SITE, self::REPAIRING];

    public const STATUS_LABELS = [
        self::SEARCHING => 'Sans mécanicien',
        self::OFFERED => 'Proposée',
        self::ACCEPTED => 'Acceptée',
        self::EN_ROUTE => 'En route',
        self::ON_SITE => 'Sur place',
        self::REPAIRING => 'En réparation',
        self::DONE => 'Terminée',
        self::CANCELLED => 'Annulée',
    ];

    public const DECISIONS = ['fixed' => 'Réparé sur place', 'towed' => 'Remorqué au garage', 'replacement' => 'Véhicule de remplacement'];

    public const CAUSES = ['wear' => 'Usure normale', 'courier_fault' => 'Négligence du livreur', 'accident' => 'Accident', 'other' => 'Autre'];

    protected $fillable = [
        'company_id', 'field_event_id', 'vehicle_id', 'courier_id', 'mechanic_id', 'source', 'title', 'icon', 'severity', 'note',
        'lat', 'lng', 'status', 'offered_at', 'offer_expires_at', 'declined', 'escalated_at', 'accepted_at', 'departed_at',
        'arrived_at', 'arrival_lat', 'arrival_lng', 'started_at', 'finished_at', 'cancelled_at', 'category', 'category_label',
        'part', 'diagnosis', 'decision', 'cause', 'parts', 'parts_amount', 'garage', 'garage_amount', 'odometer', 'remark',
        'courier_can_resume', 'vehicle_log_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'arrival_lat' => 'float',
            'arrival_lng' => 'float',
            'declined' => 'array',
            'parts' => 'array',
            'parts_amount' => 'integer',
            'garage_amount' => 'integer',
            'odometer' => 'integer',
            'courier_can_resume' => 'boolean',
            'offered_at' => 'datetime',
            'offer_expires_at' => 'datetime',
            'escalated_at' => 'datetime',
            'accepted_at' => 'datetime',
            'departed_at' => 'datetime',
            'arrived_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function fieldEvent(): BelongsTo
    {
        return $this->belongsTo(FieldEvent::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function mechanic(): BelongsTo
    {
        return $this->belongsTo(Mechanic::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(RepairFile::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(RepairPurchase::class);
    }

    /** Pas encore terminée ni annulée */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::DONE, self::CANCELLED]);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::DONE, self::CANCELLED], true);
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, self::IN_PROGRESS, true);
    }

    /** Total de l'intervention : pièces et garage extérieur */
    public function total(): int
    {
        return (int) $this->parts_amount + (int) $this->garage_amount;
    }

    /** @return list<int> */
    public function declinedIds(): array
    {
        return array_values(array_map('intval', $this->declined ?? []));
    }
}
