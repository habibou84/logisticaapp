<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use App\Enums\VehicleType;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Database\Factories\CourierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Courier extends Model
{
    /** @use HasFactory<CourierFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'user_id',
        'vehicle_type',
        'vehicle_plate',
        'pay_plan_id',
        'is_available',
        'current_lat',
        'current_lng',
        'last_location_at',
        'availability_alerted_on',
        'availability_alert_level',
        'notes',
        // Exception à la règle d'acceptation des missions de l'entreprise (null : celle de l'entreprise)
        'mission_acceptance',
        // Capacité pour la livraison automatique : colis en cours, argent en main (null : sans limite), fin de service (HH:MM)
        'max_parcels',
        'max_cash',
        'shift_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'vehicle_type' => VehicleType::class,
            'is_available' => 'boolean',
            'current_lat' => 'float',
            'current_lng' => 'float',
            'last_location_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'max_parcels' => 'integer',
            'max_cash' => 'integer',
            'availability_alerted_on' => 'date',
        ];
    }

    /**
     * Règle d'acceptation des missions pour ce livreur : son exception, sinon celle de l'entreprise.
     */
    public function acceptanceMode(): string
    {
        return $this->mission_acceptance
            ?? ($this->relationLoaded('company') ? $this->company : Company::query()->find($this->company_id))?->mission_acceptance
            ?? Company::ACCEPT_CHOOSE;
    }

    /** Peut-il rendre une mission déjà acceptée (empêchement) ? */
    public function canReportImpediment(): bool
    {
        return $this->acceptanceMode() !== Company::ACCEPT_AUTO;
    }

    /** Dernier signe de vie de l'application : appel au serveur ou position */
    public function lastSignalAt(): ?CarbonInterface
    {
        return collect([$this->last_seen_at, $this->last_location_at])->filter()->max();
    }

    /** Application ouverte : noté au plus une fois par minute */
    public function touchSeen(): void
    {
        if ($this->last_seen_at === null || $this->last_seen_at->lt(now()->subMinute())) {
            $this->forceFill(['last_seen_at' => now()])->saveQuietly();
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Zones desservies ; is_backup : zone de secours (servie quand personne de la zone principale n'est libre) */
    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(Zone::class)->withPivot('is_backup');
    }

    public function advances(): HasMany
    {
        return $this->hasMany(CourierAdvance::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(OrderExpense::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(OrderAssignment::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(CashCollection::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(CourierPayout::class);
    }

    /**
     * Plan choisi pour ce livreur (null : plan par défaut de l'entreprise).
     */
    public function payPlan(): BelongsTo
    {
        return $this->belongsTo(PayPlan::class);
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(CourierEarning::class);
    }

    public function activeAssignments(): HasMany
    {
        return $this->assignments()->whereIn('status', AssignmentStatus::activeValues());
    }
}
