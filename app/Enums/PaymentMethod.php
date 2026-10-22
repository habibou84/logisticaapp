<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Wave = 'wave';
    case OrangeMoney = 'orange_money';
    case MtnMomo = 'mtn_momo';
    case MoovMoney = 'moov_money';
    case Bank = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Espèces',
            self::Wave => 'Wave',
            self::OrangeMoney => 'Orange Money',
            self::MtnMomo => 'MTN MoMo',
            self::MoovMoney => 'Moov Money',
            self::Bank => 'Virement bancaire',
        };
    }

    /**
     * Moyens acceptés à la livraison (pas de virement bancaire).
     *
     * @return list<self>
     */
    public static function atDelivery(): array
    {
        return [self::Cash, self::Wave, self::OrangeMoney, self::MtnMomo, self::MoovMoney];
    }
}
