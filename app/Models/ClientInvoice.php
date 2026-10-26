<?php

namespace App\Models;

use App\Enums\Activity;
use App\Enums\ClientInvoiceStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Scopes\ActivityScope;
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
        'company_id', 'merchant_id', 'activity', 'number', 'period', 'status', 'total', 'amount_paid',
        'issued_at', 'due_at', 'paid_at', 'notes', 'reminded_at', 'overdue_notified_at',
        // Facture d'un département du client (une facture par département)
        'department_id',
        // TVA figée sur la facture, avoirs, identité fiscale du client à l'émission
        'vat_rate', 'vat_basis', 'total_ht', 'vat_amount', 'vat_exemption', 'credited_amount', 'client_snapshot',
        // Facture normalisée électronique (DGI)
        'fne_status', 'fne_reference', 'fne_token', 'fne_id', 'fne_error', 'fne_certified_at', 'fne_attempts', 'fne_items',
    ];

    protected function casts(): array
    {
        return [
            'status' => ClientInvoiceStatus::class,
            'activity' => Activity::class,
            'period' => 'date:Y-m-d',
            'total' => 'integer',
            'amount_paid' => 'integer',
            'vat_rate' => 'float',
            'total_ht' => 'integer',
            'vat_amount' => 'integer',
            'credited_amount' => 'integer',
            'client_snapshot' => 'array',
            'fne_items' => 'array',
            'fne_certified_at' => 'datetime',
            'issued_at' => 'date:Y-m-d',
            'due_at' => 'date:Y-m-d',
            'paid_at' => 'datetime',
            'reminded_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Périmètre de l'équipe (activités visibles)
        static::addGlobalScope(new ActivityScope);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ClientInvoiceLine::class)->orderBy('position')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ClientInvoicePayment::class)->orderBy('paid_on')->orderBy('id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(ClientCreditNote::class)->orderBy('issued_at')->orderBy('id');
    }

    /** Reste à payer : total, moins les avoirs et les paiements */
    public function balance(): int
    {
        return max(0, $this->total - $this->credited_amount - $this->amount_paid);
    }

    /** Payé au-delà de ce qui reste dû après les avoirs : à rembourser au client */
    public function refundDue(): int
    {
        return max(0, $this->amount_paid + $this->credited_amount - $this->total);
    }

    public function isOverdue(): bool
    {
        return $this->status === ClientInvoiceStatus::Issued && $this->due_at !== null && $this->due_at->isPast() && ! $this->due_at->isToday();
    }
}
