<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Clôture de la caisse d'un jour : mouvements depuis la clôture précédente par moyen, montants comptés, écart.
 */
class CashClosing extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'day', 'period_start', 'lines', 'total_in', 'total_out', 'difference', 'explanation',
        'float_kept', 'deposited', 'with_couriers', 'closed_by', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'day' => 'date:Y-m-d',
            'period_start' => 'datetime',
            'closed_at' => 'datetime',
            'lines' => 'array',
            'total_in' => 'integer',
            'total_out' => 'integer',
            'difference' => 'integer',
            'float_kept' => 'integer',
            'deposited' => 'integer',
            'with_couriers' => 'integer',
        ];
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
