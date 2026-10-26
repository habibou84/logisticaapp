<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientCreditNoteLine extends Model
{
    public $timestamps = false;

    // client_invoice_line_id : ligne de la facture reprise (null : montant libre)
    protected $fillable = ['client_credit_note_id', 'client_invoice_line_id', 'label', 'quantity', 'unit_price', 'amount'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'integer', 'amount' => 'integer'];
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(ClientInvoiceLine::class, 'client_invoice_line_id');
    }
}
