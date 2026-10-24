<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Papier d'un véhicule (assurance, visite technique, vignette…) ou d'un livreur (permis), avec sa date de fin.
 */
class VehicleDocument extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'vehicle_id', 'courier_id', 'kind', 'label', 'reference', 'issuer', 'starts_on', 'ends_on', 'cost', 'file_path', 'file_mime', 'reminded'];

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'cost' => 'integer', 'reminded' => 'integer'];
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
