<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Location-vente (le livreur devient propriétaire une fois le prix total payé) ou location d'un véhicule,
 * payée par jour, semaine ou mois, retenue sur la paie ou payée en caisse.
 */
class VehicleLease extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'vehicle_id', 'courier_id', 'kind', 'amount', 'period', 'days_per_week', 'total_amount', 'starts_on', 'payment', 'status', 'late_level', 'notes'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'days_per_week' => 'integer', 'total_amount' => 'integer', 'starts_on' => 'date:Y-m-d', 'late_level' => 'integer'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VehicleLeasePayment::class)->orderByDesc('paid_on')->orderByDesc('id');
    }
}
