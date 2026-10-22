<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paiement d'une facture de la plateforme, enregistré par le super administrateur.
 */
class PlatformPayment extends Model
{
    public const METHODS = [
        'mobile_money' => 'Mobile Money',
        'bank_transfer' => 'Virement',
        'cash' => 'Espèces',
        'other' => 'Autre',
    ];

    protected $fillable = ['platform_invoice_id', 'company_id', 'amount', 'method', 'reference', 'paid_on', 'recorded_by', 'notes'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_on' => 'date:Y-m-d',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PlatformInvoice::class, 'platform_invoice_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
