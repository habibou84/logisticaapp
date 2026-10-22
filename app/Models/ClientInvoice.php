<?php

namespace App\Models;

use App\Enums\ClientInvoiceStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Facture du mois d'un client sous formule (distribution ou MAD), payée à part.
 */
class ClientInvoice extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'merchant_id', 'number', 'period', 'status', 'total', 'amount_paid',
        'issued_at', 'due_at', 'paid_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ClientInvoiceStatus::class,
            'period' => 'date:Y-m-d',
            'total' => 'integer',
            'amount_paid' => 'integer',
            'issued_at' => 'date:Y-m-d',
            'due_at' => 'date:Y-m-d',
            'paid_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ClientInvoiceLine::class)->orderBy('position')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ClientInvoicePayment::class)->orderBy('paid_on')->orderBy('id');
    }

    public function balance(): int
    {
        return max(0, $this->total - $this->amount_paid);
    }

    public function isOverdue(): bool
    {
        return $this->status === ClientInvoiceStatus::Issued && $this->due_at !== null && $this->due_at->isPast() && ! $this->due_at->isToday();
    }
}
