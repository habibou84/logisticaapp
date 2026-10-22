<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plan de rémunération des livreurs : règles (étape, calcul, conditions) qui
 * s'additionnent, options de ramassage et bornes par course. Un plan peut être
 * partagé (attribué à plusieurs livreurs, ou plan par défaut) ou personnel
 * (exception propre à un livreur).
 */
class PayPlan extends Model
{
    use BelongsToCompany;

    public const PICKUP_PER_PARCEL = 'per_parcel';

    public const PICKUP_PER_VISIT = 'per_visit';

    public const PERIODS = [
        'weekly' => 'Chaque semaine (lundi → dimanche)',
        'biweekly' => 'Chaque quinzaine (1er → 15, 16 → fin du mois)',
        'monthly' => 'Chaque mois',
    ];

    protected $fillable = [
        'company_id', 'courier_id', 'name', 'is_default', 'pickup_mode', 'pickup_extra_parcel_amount', 'min_amount', 'max_amount',
        'base_salary', 'pay_period', 'deduction_cap_percent',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'pickup_extra_parcel_amount' => 'integer',
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'base_salary' => 'integer',
            'deduction_cap_percent' => 'integer',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(PayPlanRule::class)->orderBy('sort_order')->orderBy('id');
    }

    public function bonuses(): HasMany
    {
        return $this->hasMany(PayPlanBonus::class)->orderBy('metric')->orderBy('threshold');
    }

    /**
     * Livreur dont c'est le plan personnel.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Courier::class, 'courier_id');
    }

    public function couriers(): HasMany
    {
        return $this->hasMany(Courier::class);
    }

    public function scopeShared(Builder $query): Builder
    {
        return $query->whereNull('courier_id');
    }

    /**
     * Période de paie contenant la date.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null début et fin (jours inclus)
     */
    public function periodContaining(CarbonInterface $date): ?array
    {
        $day = CarbonImmutable::parse($date->toDateString());

        return match ($this->pay_period) {
            'weekly' => [$day->startOfWeek(CarbonInterface::MONDAY), $day->endOfWeek(CarbonInterface::SUNDAY)->startOfDay()],
            'biweekly' => $day->day <= 15
                ? [$day->startOfMonth(), $day->setDay(15)]
                : [$day->setDay(16), $day->endOfMonth()->startOfDay()],
            'monthly' => [$day->startOfMonth(), $day->endOfMonth()->startOfDay()],
            default => null,
        };
    }

    public function periodLabel(): ?string
    {
        return self::PERIODS[$this->pay_period] ?? null;
    }

    public static function defaultFor(int $companyId): ?self
    {
        return static::forCompany($companyId)->shared()->where('is_default', true)->first();
    }
}
