<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Emplacement du stock d'un marchand : chez lui (hub_id null) ou dans un entrepôt.
 */
class StockLocation extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'merchant_id', 'hub_id'];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }

    public function isWarehouse(): bool
    {
        return $this->hub_id !== null;
    }

    public function label(): string
    {
        return $this->hub_id ? ($this->hub?->name ?? 'Entrepôt') : 'Chez le marchand';
    }
}
