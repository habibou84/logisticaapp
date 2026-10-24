<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Livreur préféré ou écarté par un client (si l'entreprise le permet, Réglages › Notation).
 */
class MerchantCourierPreference extends Model
{
    use BelongsToCompany;

    public const PREFERRED = 'preferred';

    public const EXCLUDED = 'excluded';

    protected $fillable = ['company_id', 'merchant_id', 'courier_id', 'kind'];

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
