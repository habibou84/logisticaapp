<?php

namespace App\Models;

use App\Enums\PayCalc;
use App\Enums\PayEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Règle d'un plan : à telle étape (événement), tel montant (calcul), si les
 * conditions sont remplies (zones, express, fragile, véhicule, motif d'échec).
 */
class PayPlanRule extends Model
{
    protected $fillable = ['pay_plan_id', 'event', 'calc', 'amount', 'percent', 'zone_amounts', 'conditions', 'label', 'sort_order'];

    protected function casts(): array
    {
        return [
            'event' => PayEvent::class,
            'calc' => PayCalc::class,
            'amount' => 'integer',
            'percent' => 'float',
            'zone_amounts' => 'array',
            'conditions' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PayPlan::class, 'pay_plan_id');
    }
}
