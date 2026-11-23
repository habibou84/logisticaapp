<?php

namespace App\Enums;

/**
 * Pourquoi un colis repart chez le client. Le retour n'est « demandé » par le client que pour Merchant : les
 * autres viennent du destinataire (refus, tentatives épuisées) ou de l'agence.
 */
enum ReturnCause: string
{
    case Refused = 'refused';
    case MaxAttempts = 'max_attempts';
    case Company = 'company';
    case Merchant = 'merchant';

    /** Pour l'agence */
    public function label(): string
    {
        return match ($this) {
            self::Refused => 'Retour : refusé par le destinataire',
            self::MaxAttempts => 'Retour : tentatives épuisées',
            self::Company => 'Retour décidé par l\'agence',
            self::Merchant => 'Retour demandé par le client',
        };
    }

    /** Pour le client */
    public function merchantLabel(): string
    {
        return match ($this) {
            self::Refused => 'Refusé par le destinataire : le colis vous sera retourné',
            self::MaxAttempts => 'Tentatives épuisées : le colis vous sera retourné',
            self::Company => 'L\'agence vous retourne le colis',
            self::Merchant => 'Vous avez demandé le retour',
        };
    }
}
