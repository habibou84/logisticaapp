<?php

namespace App\Models;

use App\Enums\Activity;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Scopes\ActivityScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Avoir sur une facture émise d'un client : partiel (lignes, quantités, montant) ou total. Il réduit ce que
 * le client doit ; la facture déjà payée laisse un remboursement dû.
 */
class ClientCreditNote extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'merchant_id', 'client_invoice_id', 'activity', 'number', 'issued_at', 'reason',
        'vat_rate', 'total_ht', 'vat_amount', 'total', 'refund_due', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'activity' => Activity::class,
            'issued_at' => 'date:Y-m-d',
            'vat_rate' => 'float',
            'total_ht' => 'integer',
            'vat_amount' => 'integer',
            'total' => 'integer',
            'refund_due' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new ActivityScope);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ClientInvoice::class, 'client_invoice_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ClientCreditNoteLine::class)->orderBy('id');
    }
}
