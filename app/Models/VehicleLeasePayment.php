<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versement d'une location-vente ou d'une location : retenu sur la paie ou payé en caisse.
 */
class VehicleLeasePayment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'vehicle_lease_id', 'amount', 'paid_on', 'method', 'courier_earning_id', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_on' => 'date:Y-m-d'];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(VehicleLease::class, 'vehicle_lease_id');
    }
}
