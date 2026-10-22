<?php

namespace App\Enums;

enum PayoutStatus: string
{
    case Draft = 'draft';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'À payer',
            self::Paid => 'Payé',
            self::Cancelled => 'Annulé',
        };
    }
}
