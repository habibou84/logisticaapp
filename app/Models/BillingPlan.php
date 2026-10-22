<?php

namespace App\Models;

use App\Enums\BillingFormula;
use App\Enums\DeliveryDelay;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Formule de facturation d'un client : distribution (au forfait ou à l'unité) ou mise à disposition de
 * livreurs (MAD). Ses courses ne sont plus facturées une à une : elles passent sur la facture du mois.
 */
class BillingPlan extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'merchant_id', 'formula', 'monthly_fee', 'included_quantity', 'overage_price',
        'delivery_delay', 'cheque_price', 'extra_kg_price', 'starts_on', 'ends_on', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'formula' => BillingFormula::class,
            'delivery_delay' => DeliveryDelay::class,
            'monthly_fee' => 'integer',
            'included_quantity' => 'integer',
            'overage_price' => 'integer',
            'cheque_price' => 'integer',
            'extra_kg_price' => 'integer',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** Livreurs mis à disposition du client (MAD) */
    public function couriers(): BelongsToMany
    {
        return $this->belongsToMany(Courier::class, 'billing_plan_courier');
    }

    /** Formules en cours ce jour-là */
    public function scopeActiveOn(Builder $query, CarbonInterface|string $date): Builder
    {
        $day = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        return $query->whereDate('starts_on', '<=', $day)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $day));
    }

    /** Formules en cours au moins un jour entre ces deux dates */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->whereDate('starts_on', '<=', $to)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $from));
    }

    public static function activeFor(Merchant $merchant, CarbonInterface|string|null $date = null): ?self
    {
        $day = $date ?? now($merchant->company?->timezone ?? config('app.timezone'));

        return self::forCompany($merchant->company_id)->where('merchant_id', $merchant->id)
            ->activeOn($day)->latest('starts_on')->latest('id')->first();
    }

    public function describe(): string
    {
        if (! $this->formula->hasForfait()) {
            return $this->formula->label();
        }

        return $this->formula->label().' · '.Money::format($this->monthly_fee).' par mois, '
            .$this->included_quantity.' '.$this->formula->unitLabel().' inclus en '.$this->delivery_delay->label()
            .', '.Money::format($this->overage_price).' au-delà';
    }
}
