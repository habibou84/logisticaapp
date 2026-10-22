<?php

namespace App\Enums;

enum ExpenseType: string
{
    case Shipping = 'shipping';
    case Transport = 'transport';
    case Packaging = 'packaging';
    case Parking = 'parking';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Shipping => 'Frais de gare / expédition',
            self::Transport => 'Transport (taxi, moto-taxi…)',
            self::Packaging => 'Emballage',
            self::Parking => 'Stationnement, péage',
            self::Other => 'Autres frais',
        };
    }

    /**
     * Écriture du grand livre du marchand quand les frais lui sont facturés.
     */
    public function ledgerType(): LedgerEntryType
    {
        return $this === self::Shipping ? LedgerEntryType::ShippingFee : LedgerEntryType::OtherFee;
    }
}
