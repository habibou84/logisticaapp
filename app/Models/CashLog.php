<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne du journal de la caisse : une action sur l'argent, qui l'a faite et quand. Jamais modifiée ni effacée.
 */
class CashLog extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    public const ACTIONS = [
        'remittance' => 'Versement reçu',
        'remittance_cancelled' => 'Versement annulé',
        'advance' => 'Avance',
        'advance_cancelled' => 'Avance annulée',
        'collection_confirmed' => 'Paiement reçu confirmé',
        'merchant_adjustment' => 'Ajustement e-commerçant',
        'courier_adjustment' => 'Prime ou retenue',
        'payout_prepared' => 'Paiement préparé',
        'payout_paid' => 'Paiement e-commerçant',
        'payout_cancelled' => 'Paiement annulé',
        'payslip_paid' => 'Paie versée',
        'payslip_cancelled' => 'Fiche de paie annulée',
        'cash_closed' => 'Clôture de la caisse',
    ];

    protected $fillable = ['company_id', 'user_id', 'action', 'courier_id', 'merchant_id', 'amount', 'reference', 'description'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
