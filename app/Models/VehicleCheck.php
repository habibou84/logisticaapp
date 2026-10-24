<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * État des lieux d'un véhicule par le livreur, au départ ou au retour : compteur, essence, contrôle rapide.
 */
class VehicleCheck extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'vehicle_id', 'courier_id', 'moment', 'odometer', 'fuel', 'items', 'problems', 'note', 'photos', 'gps_km'];

    protected function casts(): array
    {
        return ['odometer' => 'integer', 'items' => 'array', 'problems' => 'integer', 'photos' => 'array', 'gps_km' => 'float'];
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
