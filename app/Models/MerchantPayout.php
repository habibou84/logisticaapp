<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PayoutStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reversement (relevé) au marchand : regroupe des écritures du grand livre.
 */
class MerchantPayout extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'merchant_id', 'reference', 'period_start', 'period_end', 'total_collected',
        'total_fees', 'total_shipping_fees', 'total_other_fees', 'total_storage_fees', 'total_adjustments', 'net_amount', 'status', 'method', 'transaction_ref',
        'notes', 'created_by', 'paid_by', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'method' => PaymentMethod::class,
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'total_collected' => 'integer',
            'total_fees' => 'integer',
            'total_shipping_fees' => 'integer',
            'total_other_fees' => 'integer',
            'total_storage_fees' => 'integer',
            'total_adjustments' => 'integer',
            'net_amount' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(MerchantLedgerEntry::class, 'payout_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
