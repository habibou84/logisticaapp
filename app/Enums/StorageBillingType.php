<?php

namespace App\Enums;

enum StorageBillingType: string
{
    case Free = 'free';
    case MonthlyFlat = 'monthly_flat';
    case PerUnitDay = 'per_unit_day';
    case PerOrder = 'per_order';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Gratuit',
            self::MonthlyFlat => 'Forfait mensuel',
            self::PerUnitDay => 'Par article et par jour',
            self::PerOrder => 'Par commande préparée',
        };
    }
}
