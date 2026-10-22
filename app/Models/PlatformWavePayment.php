<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paiement Wave annoncé par une entreprise pour une facture de la plateforme, en attente de vérification.
 */
class PlatformWavePayment extends Model
{
    public const PENDING = 'pending';

    public const RECEIVED = 'received';

    public const MISSING = 'missing';

    protected $fillable = ['platform_invoice_id', 'company_id', 'amount', 'payer_phone', 'user_id', 'status', 'claimed_at', 'alerts', 'decided_by', 'decided_at'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'alerts' => 'array', 'claimed_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PlatformInvoice::class, 'platform_invoice_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
