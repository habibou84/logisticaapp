<?php

namespace App\Enums;

/**
 * Activités de l'entreprise. Un client peut en avoir plusieurs ; chaque course appartient à une seule
 * (figée à sa création). L'équipe peut être limitée à certaines activités (périmètre).
 */
enum Activity: string
{
    // Courses ordinaires : frais déduits course par course des reversements
    case Ecommerce = 'ecommerce';
    // Plis et colis, au forfait ou à l'unité : facture du mois
    case Distribution = 'distribution';
    // Mise à disposition de livreurs : facture du mois
    case Mad = 'mad';

    public function label(): string
    {
        return match ($this) {
            self::Ecommerce => 'E-commerce',
            self::Distribution => 'Distribution',
            self::Mad => 'MAD',
        };
    }

    /** Préfixe de la série de factures */
    public function invoicePrefix(): string
    {
        return match ($this) {
            self::Ecommerce => 'ECO',
            self::Distribution => 'DIS',
            self::Mad => 'MAD',
        };
    }

    /** Activités facturées au mois (formules) */
    public function isBilled(): bool
    {
        return $this !== self::Ecommerce;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
