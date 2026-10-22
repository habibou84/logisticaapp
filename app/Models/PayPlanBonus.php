<?php

namespace App\Models;

use App\Enums\PayBonusMetric;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prime d'objectif : un montant si l'indicateur atteint le seuil sur la période de
 * paie. Pour un même indicateur, seul le palier le plus élevé atteint est payé.
 */
class PayPlanBonus extends Model
{
    protected $fillable = ['pay_plan_id', 'metric', 'threshold', 'min_count', 'amount', 'label', 'sort_order'];

    protected function casts(): array
    {
        return [
            'metric' => PayBonusMetric::class,
            'threshold' => 'integer',
            'min_count' => 'integer',
            'amount' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PayPlan::class, 'pay_plan_id');
    }

    public function title(): string
    {
        return $this->label ?: 'Prime '.$this->metric->goal($this->threshold);
    }
}
