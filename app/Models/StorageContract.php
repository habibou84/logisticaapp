<?php

namespace App\Models;

use App\Enums\StorageBillingType;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conditions de stockage d'un marchand dans les entrepôts de l'entreprise.
 */
class StorageContract extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'merchant_id', 'hub_id', 'billing_type', 'price', 'starts_on', 'ends_on', 'notes'];

    protected function casts(): array
    {
        return [
            'billing_type' => StorageBillingType::class,
            'price' => 'integer',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(StorageCharge::class);
    }

    public function describe(): string
    {
        return match ($this->billing_type) {
            StorageBillingType::Free => 'Gratuit',
            StorageBillingType::MonthlyFlat => Money::format($this->price).' par mois',
            StorageBillingType::PerUnitDay => Money::format($this->price).' par article et par jour',
            StorageBillingType::PerOrder => Money::format($this->price).' par commande préparée',
        };
    }
}
