<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Carnet d'un véhicule : entretien, réparation, sinistre, amende, plein de carburant, autre dépense.
 */
class VehicleLog extends Model
{
    use BelongsToCompany;

    public const KINDS = ['maintenance' => 'Entretien', 'repair' => 'Réparation', 'claim' => 'Sinistre', 'fine' => 'Amende', 'fuel' => 'Carburant', 'other' => 'Autre'];

    public const ICONS = ['maintenance' => '🔧', 'repair' => '🛠️', 'claim' => '💥', 'fine' => '👮', 'fuel' => '⛽', 'other' => '📝'];

    protected $fillable = [
        'company_id', 'vehicle_id', 'courier_id', 'kind', 'done_on', 'odometer', 'label', 'garage', 'amount', 'liters', 'paid_by',
        'courier_share', 'charged', 'plan_keys', 'immobilized_days', 'field_event_id', 'photos', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'done_on' => 'date:Y-m-d',
            'odometer' => 'integer',
            'amount' => 'integer',
            'liters' => 'float',
            'courier_share' => 'integer',
            'charged' => 'integer',
            'plan_keys' => 'array',
            'immobilized_days' => 'integer',
            'photos' => 'array',
        ];
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
