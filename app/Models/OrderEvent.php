<?php

namespace App\Models;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Journal immuable des actions sur une course : jamais modifié ni supprimé.
 */
class OrderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id',
        'assignment_id',
        'type',
        'from_status',
        'to_status',
        'incident_reason_id',
        'rescheduled_to',
        'note',
        'actor_type',
        'actor_id',
        'actor_name',
        'actor_role',
        'lat',
        'lng',
        'meta',
        'visible_to_merchant',
        // Action faite hors connexion : heure réelle sur le téléphone, et non celle de l'envoi
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => OrderEventType::class,
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'rescheduled_to' => 'date',
            'lat' => 'float',
            'lng' => 'float',
            'meta' => 'array',
            'visible_to_merchant' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Le journal des courses est immuable.'));
        static::deleting(fn () => throw new LogicException('Le journal des courses est immuable.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function incidentReason(): BelongsTo
    {
        return $this->belongsTo(IncidentReason::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(OrderAssignment::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(OrderAttachment::class, 'event_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(CourierMessage::class, 'reply_to_event_id')->orderBy('id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(FieldReportReview::class);
    }

    /**
     * Remontées terrain : notes et problèmes enregistrés par les livreurs
     * (incident, échec ou report, refus de mission, frais déclarés).
     */
    public function scopeFieldReports(Builder $query): Builder
    {
        return $query->where('actor_role', 'courier')->where(fn ($q) => $q
            ->whereIn('type', [OrderEventType::Note->value, OrderEventType::Incident->value, OrderEventType::AssignmentRefused->value, OrderEventType::ExpenseAdded->value])
            ->orWhere(fn ($q) => $q->where('type', OrderEventType::StatusChanged->value)
                ->whereIn('to_status', [OrderStatus::DeliveryFailed->value, OrderStatus::Rescheduled->value])));
    }

    /**
     * Catégorie d'une remontée terrain : incident | note | refusal | expense.
     */
    public function fieldKind(): string
    {
        return match ($this->type) {
            OrderEventType::Note => 'note',
            OrderEventType::AssignmentRefused => 'refusal',
            OrderEventType::ExpenseAdded => 'expense',
            default => 'incident',
        };
    }
}
