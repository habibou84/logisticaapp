<?php

namespace App\Enums;

enum VehicleType: string
{
    case Moto = 'moto';
    case Velo = 'velo';
    case Voiture = 'voiture';
    case Tricycle = 'tricycle';
    case Pieton = 'pieton';
}
