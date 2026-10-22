<?php

namespace App\Enums;

enum SurchargeType: string
{
    case Express = 'express';
    case Fragile = 'fragile';
    // Appliqué si le poids est dans [min_value, max_value[ (kg)
    case Weight = 'weight';

    public function label(): string
    {
        return match ($this) {
            self::Express => 'Express',
            self::Fragile => 'Fragile',
            self::Weight => 'Poids',
        };
    }
}
