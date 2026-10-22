<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Facturation mensuelle d'un contrat de stockage.
 */
class StorageCharge extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'merchant_id', 'storage_contract_id', 'period', 'quantity', 'amount', 'ledger_entry_id'];

    protected function casts(): array
    {
        return ['period' => 'date:Y-m-d', 'quantity' => 'integer', 'amount' => 'integer'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(StorageContract::class, 'storage_contract_id');
    }
}
