<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Frais fixe de l'entreprise (loyer, salaires de l'équipe, téléphone…) : chaque mois ou une seule fois.
 * Compté dans la marge nette des statistiques de rentabilité.
 */
class FixedCost extends Model
{
    use BelongsToCompany;

    public const CATEGORIES = [
        'rent' => 'Loyer et charges',
        'staff' => 'Salaires de l\'équipe',
        'telecom' => 'Téléphone et internet',
        'insurance' => 'Assurances',
        'marketing' => 'Publicité',
        'taxes' => 'Impôts et taxes',
        'other' => 'Autre',
    ];

    public const FREQUENCIES = ['monthly' => 'Chaque mois', 'once' => 'Une fois'];

    protected $fillable = ['company_id', 'label', 'category', 'amount', 'frequency', 'starts_on', 'ends_on', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
    }

    /**
     * Part du frais sur une période : un frais mensuel au prorata des jours de chaque mois couverts, un frais unique
     * en entier si sa date tombe dans la période.
     */
    public function amountBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $start = CarbonImmutable::parse($this->starts_on->toDateString());
        if ($this->frequency === 'once') {
            return $start->betweenIncluded($from->startOfDay(), $to->endOfDay()) ? $this->amount : 0;
        }
        $end = $this->ends_on ? CarbonImmutable::parse($this->ends_on->toDateString()) : $to;
        $from = $from->startOfDay()->max($start);
        $to = $to->startOfDay()->min($end);
        $total = 0.0;
        for ($day = $from->startOfMonth(); $day->lessThanOrEqualTo($to); $day = $day->addMonth()) {
            $a = $day->max($from);
            $b = $day->endOfMonth()->startOfDay()->min($to);
            if ($b->lessThan($a)) {
                continue;
            }
            $total += $this->amount * ($a->diffInDays($b) + 1) / $day->daysInMonth;
        }

        return (int) round($total);
    }
}
