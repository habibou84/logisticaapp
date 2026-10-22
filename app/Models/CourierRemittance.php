<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versement d'un livreur à la caisse de l'entreprise.
 */
class CourierRemittance extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'courier_id', 'amount_expected', 'amount_received', 'difference',
        'received_by', 'received_at', 'notes', 'parcels',
    ];

    protected function casts(): array
    {
        return [
            'amount_expected' => 'integer',
            'amount_received' => 'integer',
            'difference' => 'integer',
            'received_at' => 'datetime',
            'parcels' => 'array',
        ];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function collections(): HasMany
    {
        return $this->hasMany(CashCollection::class, 'remittance_id');
    }
}
