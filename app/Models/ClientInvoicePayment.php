<?php

namespace App\Models;

use App\Enums\InvoicePaymentMethod;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientInvoicePayment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'client_invoice_id', 'amount', 'method', 'reference', 'paid_on', 'recorded_by', 'notes'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'method' => InvoicePaymentMethod::class, 'paid_on' => 'date:Y-m-d'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ClientInvoice::class, 'client_invoice_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
