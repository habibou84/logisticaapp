<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Événement terrain signalé par un livreur (panne, accident, contrôle…), avec les courses qu'il touche.
 * Ouvert, puis pris en charge par l'équipe, puis résolu (par l'équipe, ou quand le livreur reprend).
 */
class FieldEvent extends Model
{
    use BelongsToCompany;

    public const OPEN = 'open';

    public const TAKEN = 'taken';

    public const RESOLVED = 'resolved';

    // Gravités, de la plus légère à la plus grave
    public const SEVERITIES = ['info', 'hindrance', 'blocking', 'urgent'];

    // Le livreur peut continuer, est bloqué un moment, ou arrête pour la journée
    public const CAPACITIES = ['continue', 'blocked', 'stopped'];

    protected $fillable = [
        'company_id', 'courier_id', 'client_uuid', 'type', 'type_label', 'icon', 'severity', 'capacity', 'blocked_until',
        'note', 'lat', 'lng', 'cost', 'status', 'taken_by', 'taken_at', 'resolved_by', 'resolved_at', 'resolution',
        'resumed_at', 'excused', 'effects', 'reminders', 'last_reminded_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'blocked_until' => 'datetime',
            'taken_at' => 'datetime',
            'resolved_at' => 'datetime',
            'resumed_at' => 'datetime',
            'last_reminded_at' => 'datetime',
            'lat' => 'float',
            'lng' => 'float',
            'cost' => 'integer',
            'excused' => 'boolean',
            'effects' => 'array',
            'reminders' => 'integer',
        ];
    }

    /** Pas encore résolu */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->where('status', '!=', self::RESOLVED);
    }

    public function isResolved(): bool
    {
        return $this->status === self::RESOLVED;
    }

    /** Le livreur ne peut plus rouler (bloqué ou arrêté), tant que ce n'est pas réglé */
    public function blocksCourier(): bool
    {
        return ! $this->isResolved() && $this->capacity !== 'continue';
    }

    /**
     * Événement en cours le plus grave de chaque livreur (carte, livreurs disponibles, liste des livreurs).
     *
     * @return array<int, array{id: int, icon: ?string, label: string, severity: string, capacity: string, blocked_until: mixed}>
     */
    public static function currentByCourier(): array
    {
        $rank = array_flip(self::SEVERITIES);

        return self::query()->unresolved()->where('created_at', '>=', now()->subDays(3))->get()
            ->groupBy('courier_id')
            ->map(fn ($events) => $events->sortByDesc(fn (self $e) => [$e->capacity !== 'continue', $rank[$e->severity] ?? 0, $e->id])->first())
            ->map(fn (self $e) => [
                'id' => $e->id,
                'icon' => $e->icon,
                'label' => $e->type_label,
                'severity' => $e->severity,
                'capacity' => $e->capacity,
                'blocked_until' => $e->blocked_until,
            ])->all();
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'field_event_order');
    }

    public function files(): HasMany
    {
        return $this->hasMany(FieldEventFile::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(FieldEventMessage::class)->orderBy('id');
    }

    public function taker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
