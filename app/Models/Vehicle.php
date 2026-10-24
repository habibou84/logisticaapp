<?php

namespace App\Models;

use App\Enums\VehicleType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Véhicule du parc : fiche, propriétaire, état, compteur et plan d'entretien. courier_id : le livreur qui
 * l'a en ce moment (attitré ou pris pour la journée).
 */
class Vehicle extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['active' => 'En service', 'broken' => 'En panne', 'maintenance' => 'À l\'entretien', 'immobilized' => 'Immobilisé', 'out' => 'Hors service', 'sold' => 'Vendu'];

    public const OWNERSHIPS = ['company' => 'Entreprise', 'courier' => 'Véhicule du livreur', 'rental' => 'Location', 'lease' => 'Location-vente'];

    protected $fillable = [
        'company_id', 'type', 'plate', 'brand', 'model', 'year', 'color', 'vin', 'photo_path', 'capacity_parcels', 'capacity_kg',
        'has_box', 'ownership', 'owner_courier_id', 'status', 'courier_id', 'odometer', 'odometer_at', 'purchase_price',
        'purchased_on', 'maintenance_plan', 'maintenance_reminded', 'field_event_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => VehicleType::class,
            'year' => 'integer',
            'capacity_parcels' => 'integer',
            'capacity_kg' => 'integer',
            'has_box' => 'boolean',
            'odometer' => 'integer',
            'odometer_at' => 'datetime',
            'purchase_price' => 'integer',
            'purchased_on' => 'date:Y-m-d',
            'maintenance_plan' => 'array',
            'maintenance_reminded' => 'array',
        ];
    }

    public function name(): string
    {
        return trim($this->plate.' · '.trim(($this->brand ?? '').' '.($this->model ?? '')), ' ·');
    }

    /** Nouveau relevé du compteur (jamais en arrière) */
    public function readOdometer(?int $km): void
    {
        if ($km !== null && $km >= $this->odometer) {
            $this->forceFill(['odometer' => $km, 'odometer_at' => now()]);
        }
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Courier::class, 'owner_courier_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(VehicleAssignment::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(VehicleCheck::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(VehicleLog::class);
    }

    public function leases(): HasMany
    {
        return $this->hasMany(VehicleLease::class);
    }
}
