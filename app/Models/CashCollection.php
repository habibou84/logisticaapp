<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Argent encaissé auprès du destinataire d'une course livrée.
 */
class CashCollection extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'order_id', 'courier_id', 'amount_expected', 'amount_collected',
        'method', 'received_by_company', 'transaction_ref', 'collected_at', 'remittance_id',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'received_by_company' => 'boolean',
            'amount_expected' => 'integer',
            'amount_collected' => 'integer',
            'collected_at' => 'datetime',
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

    public function remittance(): BelongsTo
    {
        return $this->belongsTo(CourierRemittance::class, 'remittance_id');
    }

    /**
     * Encaissements encore entre les mains d'un livreur (à verser à la caisse).
     */
    public function scopeInCourierHands(Builder $query): Builder
    {
        return $query->whereNull('remittance_id')->where('received_by_company', false);
    }

    /**
     * L'argent est arrivé dans la caisse de l'entreprise.
     */
    public function isSettled(): bool
    {
        return $this->received_by_company || $this->remittance_id !== null;
    }
}
