<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historique des moyens de paiement d'un marchand : qui a ajouté, changé, validé ou retiré quoi.
 */
class MerchantPaymentMethodEvent extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'merchant_id', 'payment_method_id', 'action', 'summary', 'user_id', 'by_merchant'];

    protected function casts(): array
    {
        return ['by_merchant' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
