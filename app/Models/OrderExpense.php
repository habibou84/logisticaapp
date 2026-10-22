<?php

namespace App\Models;

use App\Enums\ExpenseType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frais engagés pour une course (gare, transport, emballage…), payés par le livreur
 * ou l'agence, et facturés au marchand ou supportés par l'agence.
 */
class OrderExpense extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'order_id', 'type', 'label', 'amount', 'paid_by', 'courier_id', 'billed_to',
        'ledger_entry_id', 'remittance_id', 'created_by', 'cancelled_at', 'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => ExpenseType::class,
            'amount' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(MerchantLedgerEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    /**
     * Frais avancés par un livreur et pas encore remboursés (déduits de son prochain versement).
     */
    public function scopeOwedToCourier(Builder $query): Builder
    {
        return $query->active()->where('paid_by', 'courier')->whereNull('remittance_id');
    }

    public function description(): string
    {
        return $this->label ? "{$this->type->label()} : {$this->label}" : $this->type->label();
    }
}
