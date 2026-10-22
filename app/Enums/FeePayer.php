<?php

namespace App\Enums;

enum FeePayer: string
{
    // Frais déduits des reversements au marchand
    case Merchant = 'merchant';
    // Frais ajoutés au montant encaissé auprès du destinataire
    case Recipient = 'recipient';
}
