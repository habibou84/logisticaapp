<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Règle du planning : un livreur (et son remplaçant) ramasse ou livre dans des zones ou chez des clients,
 * tous les jours, certains jours de la semaine, à une date ou sur une période.
 */
class CourierRule extends Model
{
    use BelongsToCompany;

    public const PICKUP = 'pickup';

    public const DELIVERY = 'delivery';

    protected $fillable = [
        'company_id', 'type', 'courier_id', 'backup_courier_id', 'zone_ids', 'merchant_ids', 'days',
        'starts_on', 'ends_on', 'is_active', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'zone_ids' => 'array',
            'merchant_ids' => 'array',
            'days' => 'array',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Courier::class, 'backup_courier_id');
    }

    /** La règle vise des clients précis (sinon des zones) */
    public function targetsMerchants(): bool
    {
        return ! empty($this->merchant_ids);
    }

    /** S'applique ce jour-là (AAAA-MM-JJ) */
    public function appliesOn(string $date): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->starts_on && $date < $this->starts_on->toDateString()) {
            return false;
        }
        if ($this->ends_on && $date > $this->ends_on->toDateString()) {
            return false;
        }
        $days = array_map('intval', $this->days ?? []);

        return $days === [] || in_array((int) date('N', strtotime($date)), $days, true);
    }
}
