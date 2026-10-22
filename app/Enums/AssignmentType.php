<?php

namespace App\Enums;

enum AssignmentType: string
{
    case Pickup = 'pickup';
    case Delivery = 'delivery';
    case Return = 'return';

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Ramassage',
            self::Delivery => 'Livraison',
            self::Return => 'Retour',
        };
    }

    /**
     * Colonne dénormalisée sur la course pour le livreur de ce type.
     */
    public function courierColumn(): string
    {
        return $this->value.'_courier_id';
    }
}
