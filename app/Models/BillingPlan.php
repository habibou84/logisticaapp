<?php

namespace App\Models;

use App\Enums\Activity;
use App\Enums\BillingFormula;
use App\Enums\DeliveryDelay;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Scopes\ActivityScope;
use App\Services\Orders\ActivityOptions;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
        'company_id', 'merchant_id', 'formula', 'activity', 'monthly_fee', 'included_quantity', 'overage_price',
        'delivery_delay', 'cheque_price', 'extra_kg_price', 'starts_on', 'ends_on', 'notes', 'usage_alert', 'internal_delay_hours',
        'lot_label', 'lot_quantity', 'lot_price', 'lot_due_on', 'options',
    ];

    protected function casts(): array
    {
        return [
            'formula' => BillingFormula::class,
            'activity' => Activity::class,
            'delivery_delay' => DeliveryDelay::class,
            'monthly_fee' => 'integer',
            'included_quantity' => 'integer',
            'overage_price' => 'integer',
            'cheque_price' => 'integer',
            'extra_kg_price' => 'integer',
            'internal_delay_hours' => 'integer',
            'lot_quantity' => 'integer',
            'lot_price' => 'integer',
            'lot_due_on' => 'date:Y-m-d',
            'options' => 'array',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        // Périmètre de l'équipe (activités visibles)
        static::addGlobalScope(new ActivityScope);
        // L'activité suit la formule : distribution ou MAD
        static::saving(fn (self $plan) => $plan->activity = $plan->formula->activity());
        // Réglages du contrat changés : relus à la prochaine course
        static::saved(fn () => app(ActivityOptions::class)->forget());
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** Livreurs mis à disposition du client (MAD) */
    /**
     * Zones couvertes par le forfait du contrat (communes, villes de l'intérieur ; leurs quartiers suivent).
     * Aucune : les communes marquées « Forfait » (Réglages › Zones).
     */
    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(Zone::class, 'billing_plan_zone');
    }

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

    /**
     * Formule en cours du client pour une activité (distribution ou MAD), hors lots ponctuels.
     */
    public static function activeFor(Merchant $merchant, Activity $activity, CarbonInterface|string|null $date = null): ?self
    {
        $day = $date ?? now($merchant->company?->timezone ?? config('app.timezone'));

        return self::forCompany($merchant->company_id)->where('merchant_id', $merchant->id)
            ->where('activity', $activity->value)->contracts()
            ->activeOn($day)->latest('starts_on')->latest('id')->first();
    }

    /**
     * Lots ponctuels ouverts du client (distribution), du plus ancien au plus récent.
     *
     * @return Collection<int, self>
     */
    public static function openLotsFor(Merchant $merchant, CarbonInterface|string|null $date = null)
    {
        $day = $date ?? now($merchant->company?->timezone ?? config('app.timezone'));

        return self::forCompany($merchant->company_id)->where('merchant_id', $merchant->id)->lots()
            ->activeOn($day)->orderBy('starts_on')->orderBy('id')->get();
    }

    /** Contrats (mensuels ou à l'unité), sans les lots ponctuels */
    public function scopeContracts(Builder $query): Builder
    {
        return $query->where('formula', '!=', BillingFormula::DistributionLot->value);
    }

    public function scopeLots(Builder $query): Builder
    {
        return $query->where('formula', BillingFormula::DistributionLot->value);
    }

    /** Nom du lot : « Lot n° 12 » sans libellé */
    public function lotName(): string
    {
        return $this->lot_label ?: 'Lot n° '.$this->id;
    }

    /**
     * Résumé pour les écrans (fiche client, saisie des courses).
     *
     * @return array<string, mixed>
     */
    public function brief(): array
    {
        return [
            'id' => $this->id,
            'activity' => $this->activity->value,
            'formula' => $this->formula->value,
            'formula_label' => $this->formula->label(),
            'has_forfait' => $this->formula->hasForfait(),
            'delivery_delay' => $this->delivery_delay->value,
            'summary' => $this->describe(),
            ...($this->formula->isLot() ? [
                'lot_name' => $this->lotName(),
                'lot_quantity' => $this->lot_quantity,
                'lot_price' => $this->lot_price,
                'lot_due_on' => $this->lot_due_on?->toDateString(),
            ] : []),
        ];
    }

    public function describe(): string
    {
        if ($this->formula->isLot()) {
            return $this->lotName().' : '.$this->lot_quantity.' colis en '.$this->delivery_delay->label()
                .($this->lot_price !== null ? ' pour '.Money::format($this->lot_price) : ' au prix unitaire de la grille')
                .' ; au-delà, au prix unitaire'.($this->lot_due_on ? ' ; à distribuer avant le '.$this->lot_due_on->format('d/m/Y') : '');
        }
        if (! $this->formula->hasForfait()) {
            return $this->formula->label();
        }

        return $this->formula->label().' · '.Money::format($this->monthly_fee).' par mois, '
            .$this->included_quantity.' '.$this->formula->unitLabel().' inclus en '.$this->delivery_delay->label()
            .' ; au-delà : '.Money::format($this->overage_price).' à Abidjan, intérieur au tarif de la ville';
    }
}
