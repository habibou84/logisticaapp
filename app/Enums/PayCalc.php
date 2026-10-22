<?php

namespace App\Enums;

/**
 * Calcul du montant d'une règle de rémunération.
 */
enum PayCalc: string
{
    case Fixed = 'fixed';
    case PercentFee = 'percent_fee';
    case PercentCollected = 'percent_collected';
    case ZoneGrid = 'zone_grid';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Montant fixe',
            self::PercentFee => '% des frais de livraison',
            self::PercentCollected => '% du montant encaissé',
            self::ZoneGrid => 'Montant selon la zone',
        };
    }
}
