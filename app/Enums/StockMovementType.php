<?php

namespace App\Enums;

enum StockMovementType: string
{
    // Marchandise déposée (à l'entrepôt ou déclarée chez le marchand)
    case Receipt = 'receipt';
    // Marchandise reprise par le marchand ou sortie hors course
    case Withdrawal = 'withdrawal';
    // Correction après inventaire
    case Adjustment = 'adjustment';
    // Réservée par une course
    case Reservation = 'reservation';
    // Réservation libérée (course annulée, refusée ou colis remis en stock)
    case Release = 'release';
    // Sortie définitive : course livrée
    case Shipment = 'shipment';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Entrée',
            self::Withdrawal => 'Retrait',
            self::Adjustment => 'Inventaire',
            self::Reservation => 'Réservé',
            self::Release => 'Libéré',
            self::Shipment => 'Livré',
        };
    }
}
