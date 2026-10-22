<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Absence d'un livreur (congé, maladie…) : ses règles du planning passent à son remplaçant.
 */
class CourierAbsence extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'courier_id', 'starts_on', 'ends_on', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }
}
