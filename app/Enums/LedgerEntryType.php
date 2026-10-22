<?php

namespace App\Enums;

enum LedgerEntryType: string
{
    // Montant encaissé auprès du destinataire, dû au marchand
    case CodCredit = 'cod_credit';
    // Frais de livraison retenus
    case DeliveryFee = 'delivery_fee';
    // Frais facturés pour un colis retourné
    case ReturnFee = 'return_fee';
    // Frais d'expédition payés par le livreur à la gare ou au transporteur
    case ShippingFee = 'shipping_fee';
    // Autres frais engagés pour la course (transport, emballage, stationnement…)
    case OtherFee = 'other_fee';
    // Stockage des produits dans un entrepôt de l'entreprise (facturé chaque mois)
    case StorageFee = 'storage_fee';
    // Indemnité versée au marchand pour un colis perdu
    case LostCompensation = 'lost_compensation';
    case Adjustment = 'adjustment';
    // Reversement effectué : solde les écritures du relevé
    case Payout = 'payout';

    public function label(): string
    {
        return match ($this) {
            self::CodCredit => 'Encaissement',
            self::DeliveryFee => 'Frais de livraison',
            self::ReturnFee => 'Frais de retour',
            self::ShippingFee => 'Frais d\'expédition',
            self::OtherFee => 'Autres frais',
            self::StorageFee => 'Frais de stockage',
            self::LostCompensation => 'Indemnité colis perdu',
            self::Adjustment => 'Ajustement',
            self::Payout => 'Reversement',
        };
    }
}
