<?php

namespace App\Enums;

enum EarningType: string
{
    case Pickup = 'pickup';
    case Delivery = 'delivery';
    case Return = 'return';
    // Déplacement pour une livraison qui n'a pas abouti (client absent…)
    case FailedAttempt = 'failed_attempt';
    // Manque constaté lors d'un versement à la caisse (montant négatif)
    case Shortfall = 'shortfall';
    // Retenue pour un colis perdu par le livreur (montant négatif)
    case LostParcel = 'lost_parcel';
    case Adjustment = 'adjustment';
    // Salaire de base d'une période (plan salarié ou mixte)
    case Salary = 'salary';
    // Retenue au-delà du plafond : sortie de cette fiche (+), reprise sur la suivante (−)
    case Carryover = 'carryover';
    // Prime d'objectif atteinte sur une période (plan de paie)
    case Bonus = 'bonus';

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Ramassage',
            self::Delivery => 'Livraison',
            self::Return => 'Retour',
            self::FailedAttempt => 'Tentative ratée',
            self::Shortfall => 'Manque de caisse',
            self::LostParcel => 'Retenue colis perdu',
            self::Adjustment => 'Ajustement',
            self::Salary => 'Salaire de base',
            self::Carryover => 'Retenue reportée',
            self::Bonus => 'Prime d\'objectif',
        };
    }
}
