<?php

namespace App\Models;

use App\Enums\LedgerEntryType;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Écriture du grand livre d'un marchand. Solde = somme des montants.
 */
class MerchantLedgerEntry extends Model
{
    use BelongsToCompany, IsAppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'merchant_id', 'type', 'amount', 'order_id', 'payout_id', 'description', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount' => 'integer',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(MerchantPayout::class, 'payout_id');
    }
}
