<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PayoutStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Paie d'un livreur : regroupe ses gains non payés.
 */
class CourierPayout extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'courier_id', 'reference', 'period_start', 'period_end', 'amount', 'status', 'automatic',
        'method', 'compensated', 'transaction_ref', 'created_by', 'paid_by', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'method' => PaymentMethod::class,
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'amount' => 'integer',
            'automatic' => 'boolean',
            'compensated' => 'boolean',
            'paid_at' => 'datetime',
        ];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(CourierEarning::class, 'payout_id');
    }
}
