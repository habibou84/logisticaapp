<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientInvoiceLine extends Model
{
    // Lignes calculées depuis la formule, les courses et les suppléments du mois ; « adjustment » : ajoutée à la main
    public const KINDS = ['forfait', 'included', 'overage', 'extra_kg', 'cheque', 'interior', 'unit', 'outside', 'lot', 'lot_extra', 'supplement', 'adjustment'];

    protected $fillable = ['client_invoice_id', 'kind', 'label', 'quantity', 'unit_price', 'amount', 'manual', 'position'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'integer', 'amount' => 'integer', 'manual' => 'boolean', 'position' => 'integer'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ClientInvoice::class, 'client_invoice_id');
    }
}
