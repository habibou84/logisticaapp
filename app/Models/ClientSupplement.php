<?php

namespace App\Models;

use App\Enums\Activity;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Scopes\ActivityScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Supplément d'un client sous contrat : prestation exceptionnelle facturée à part sur la facture du mois
 * de son activité (section « Suppléments »). Modifiable tant que cette facture n'est pas émise.
 */
class ClientSupplement extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'merchant_id', 'activity', 'order_id', 'client_invoice_id', 'performed_on', 'label',
        'quantity', 'unit_price', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'activity' => Activity::class,
            'performed_on' => 'date:Y-m-d',
            'quantity' => 'integer',
            'unit_price' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new ActivityScope);
    }

    public function amount(): int
    {
        return $this->quantity * $this->unit_price;
    }

    public function isLocked(): bool
    {
        return $this->client_invoice_id !== null;
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ClientInvoice::class, 'client_invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
