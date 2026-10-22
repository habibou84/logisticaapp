<?php

namespace App\Enums;

enum ClientInvoiceStatus: string
{
    // Calculée, modifiable (recalcul, lignes ajoutées à la main), pas encore envoyée
    case Draft = 'draft';
    // Numérotée et envoyée : en attente de paiement
    case Issued = 'issued';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Issued => 'À payer',
            self::Paid => 'Payée',
            self::Void => 'Annulée',
        };
    }
}
