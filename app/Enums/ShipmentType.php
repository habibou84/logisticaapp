<?php

namespace App\Enums;

/**
 * Type d'envoi des clients sous formule : pli (0 à 500 g), colis (0 à 5 kg), chèque (hors forfait).
 */
enum ShipmentType: string
{
    case Pli = 'pli';
    case Colis = 'colis';
    case Cheque = 'cheque';

    /** Poids d'un pli au plus et poids compris dans le prix d'un colis (kg) */
    public const PLI_MAX_KG = 0.5;

    public const COLIS_MAX_KG = 5.0;

    public function label(): string
    {
        return match ($this) {
            self::Pli => 'Pli',
            self::Colis => 'Colis',
            self::Cheque => 'Chèque',
        };
    }
}
