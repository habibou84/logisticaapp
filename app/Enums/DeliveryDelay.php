<?php

namespace App\Enums;

/**
 * Délai de livraison souhaité par le client sous formule : il fixe le prix.
 */
enum DeliveryDelay: string
{
    case H24 = '24h';
    case H48 = '48h';
    case H72 = '72h';

    public function label(): string
    {
        return match ($this) {
            self::H24 => '24 h',
            self::H48 => '48 h',
            self::H72 => '72 h',
        };
    }

    public function hours(): int
    {
        return (int) $this->value;
    }
}
