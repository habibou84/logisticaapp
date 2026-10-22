<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Argent remis par la caisse à un livreur avant une mission (frais de gare…) ;
 * ce qu'il n'a pas dépensé revient à la caisse lors de son versement.
 * Montant négatif lié à une fiche de paie : paie que le livreur garde sur
 * l'argent encaissé (déduite de ce qu'il verse).
 */
class CourierAdvance extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'courier_id', 'amount', 'reason', 'order_id', 'courier_payout_id', 'given_by', 'given_at', 'remittance_id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'given_at' => 'datetime',
        ];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function giver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'given_by');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(CourierPayout::class, 'courier_payout_id');
    }

    /**
     * Avances réelles (hors paie gardée sur l'encaissé).
     */
    public function scopeCash(Builder $query): Builder
    {
        return $query->whereNull('courier_payout_id');
    }

    public function scopeUnsettled(Builder $query): Builder
    {
        return $query->whereNull('remittance_id');
    }
}
