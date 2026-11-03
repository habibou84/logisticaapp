<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Relevé du soir d'une entreprise : courses non traitées, délai interne dépassé, sans livreur, livrées du jour…
 */
class DailySnapshot extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'day', 'data'];

    protected function casts(): array
    {
        return ['day' => 'date:Y-m-d', 'data' => 'array'];
    }
}
