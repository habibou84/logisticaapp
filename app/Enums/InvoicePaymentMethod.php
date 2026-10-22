<?php

namespace App\Enums;

/**
 * Moyens de paiement d'une facture client.
 */
enum InvoicePaymentMethod: string
{
    case Cash = 'cash';
    case MobileMoney = 'mobile_money';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Espèces',
            self::MobileMoney => 'Mobile Money',
            self::BankTransfer => 'Virement',
            self::Cheque => 'Chèque',
            self::Other => 'Autre',
        };
    }
}
