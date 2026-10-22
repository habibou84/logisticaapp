<?php

namespace App\Enums;

/**
 * Formule de facturation d'un client en dehors des courses ordinaires (frais déduits course par course).
 */
enum BillingFormula: string
{
    // Forfait mensuel : plis et colis inclus sur les communes du forfait, chacun au-delà en supplément
    case DistributionContract = 'distribution_contract';
    // Sans contrat : chaque pli ou colis au prix du délai choisi
    case DistributionUnit = 'distribution_unit';
    // Mise à disposition de livreurs : forfait mensuel, courses incluses, chacune au-delà en supplément
    case MadContract = 'mad_contract';

    public function label(): string
    {
        return match ($this) {
            self::DistributionContract => 'Distribution sous contrat',
            self::DistributionUnit => 'Distribution à l\'unité',
            self::MadContract => 'Mise à disposition (MAD)',
        };
    }

    public function hasForfait(): bool
    {
        return $this !== self::DistributionUnit;
    }

    public function isMad(): bool
    {
        return $this === self::MadContract;
    }

    /** Ce que compte le forfait : plis et colis, ou courses */
    public function unitLabel(): string
    {
        return $this === self::MadContract ? 'course(s)' : 'pli(s) et colis';
    }
}
