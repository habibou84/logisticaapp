<?php

namespace App\Enums;

enum ReportFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Chaque jour',
            self::Weekly => 'Chaque semaine',
        };
    }
}
