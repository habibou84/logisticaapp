<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pointage d'arrivée d'un livreur, un par jour : sur place (GPS vérifié par le serveur) ou par un responsable
 * (avec un motif). Le lieu est recopié tel qu'il était ce jour-là.
 */
class CourierShift extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'courier_id', 'work_date', 'kind', 'billing_plan_id', 'merchant_id', 'courier_routine_id',
        'place_label', 'place_lat', 'place_lng', 'radius', 'expected_at', 'clocked_in_at', 'late_minutes',
        'lat', 'lng', 'accuracy', 'distance', 'method', 'recorded_by', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'place_lat' => 'float',
            'place_lng' => 'float',
            'radius' => 'integer',
            'expected_at' => 'datetime',
            'clocked_in_at' => 'datetime',
            'late_minutes' => 'integer',
            'lat' => 'float',
            'lng' => 'float',
            'accuracy' => 'integer',
            'distance' => 'integer',
        ];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function brief(): array
    {
        return [
            'id' => $this->id,
            'work_date' => $this->work_date->toDateString(),
            'kind' => $this->kind,
            'place_label' => $this->place_label,
            'expected_at' => $this->expected_at?->toIso8601String(),
            'clocked_in_at' => $this->clocked_in_at->toIso8601String(),
            'late_minutes' => $this->late_minutes,
            'distance' => $this->distance,
            'accuracy' => $this->accuracy,
            'method' => $this->method,
            'reason' => $this->reason,
            'recorded_by' => $this->recorder?->name,
        ];
    }
}
