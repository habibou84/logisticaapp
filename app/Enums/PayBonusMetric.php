<?php

namespace App\Enums;

/**
 * Indicateur mesuré sur la période de paie pour une prime d'objectif.
 */
enum PayBonusMetric: string
{
    case Deliveries = 'deliveries';
    case Pickups = 'pickups';
    case SuccessRate = 'success_rate';
    case WorkedDays = 'worked_days';

    public function label(): string
    {
        return match ($this) {
            self::Deliveries => 'Livraisons réussies',
            self::Pickups => 'Ramassages',
            self::SuccessRate => 'Taux de livraisons réussies (%)',
            self::WorkedDays => 'Jours travaillés',
        };
    }

    /**
     * Libellé court de l'objectif, ex. « 100 livraisons réussies ».
     */
    public function goal(int $threshold): string
    {
        return match ($this) {
            self::Deliveries => "{$threshold} livraisons réussies",
            self::Pickups => "{$threshold} ramassages",
            self::SuccessRate => "{$threshold} % de livraisons réussies",
            self::WorkedDays => "{$threshold} jours travaillés",
        };
    }
}
