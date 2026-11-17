<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pointage refusé (trop loin, position imprécise, trop tôt, sans position) : gardé pour le contrôle.
 */
class CourierShiftAttempt extends Model
{
    use BelongsToCompany;

    public const REASONS = [
        'too_far' => 'Trop loin du lieu',
        'inaccurate' => 'Position imprécise',
        'too_early' => 'Trop tôt',
        'no_position' => 'Sans position',
    ];

    public $timestamps = false;

    protected $fillable = ['company_id', 'courier_id', 'work_date', 'place_label', 'lat', 'lng', 'accuracy', 'distance', 'reason', 'attempted_at'];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'lat' => 'float',
            'lng' => 'float',
            'accuracy' => 'integer',
            'distance' => 'integer',
            'attempted_at' => 'datetime',
        ];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }
}
