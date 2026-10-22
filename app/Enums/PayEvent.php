<?php

namespace App\Enums;

/**
 * Étape d'une course qui rapporte un gain au livreur.
 */
enum PayEvent: string
{
    case Pickup = 'pickup';
    case Delivery = 'delivery';
    case FailedAttempt = 'failed_attempt';
    case Return = 'return';
    case Shipping = 'shipping';

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Ramassage',
            self::Delivery => 'Livraison réussie',
            self::FailedAttempt => 'Tentative de livraison ratée',
            self::Return => 'Retour au marchand',
            self::Shipping => 'Remise au transporteur (expédition)',
        };
    }

    public function earningType(): EarningType
    {
        return match ($this) {
            self::Pickup => EarningType::Pickup,
            self::Delivery, self::Shipping => EarningType::Delivery,
            self::FailedAttempt => EarningType::FailedAttempt,
            self::Return => EarningType::Return,
        };
    }
}
