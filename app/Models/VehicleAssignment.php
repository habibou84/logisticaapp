<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Remise d'un véhicule à un livreur (attitré ou pour la journée) : qui l'avait, quand, avec quel compteur.
 */
class VehicleAssignment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'vehicle_id', 'courier_id', 'kind', 'starts_at', 'ends_at', 'odometer_start', 'odometer_end', 'created_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'odometer_start' => 'integer', 'odometer_end' => 'integer'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }
}
