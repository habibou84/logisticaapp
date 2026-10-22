<?php

namespace App\Enums;

/**
 * Portées d'une clé API. Les référentiels (zones, devis) sont accessibles à toute clé valide.
 */
enum ApiScope: string
{
    case OrdersRead = 'orders:read';
    case OrdersWrite = 'orders:write';
    case StockRead = 'stock:read';
    case WebhooksWrite = 'webhooks:write';
    case PayoutsRead = 'payouts:read';

    public function label(): string
    {
        return match ($this) {
            self::OrdersRead => 'Lire les courses et le point',
            self::OrdersWrite => 'Créer et annuler des courses',
            self::StockRead => 'Lire les produits et le stock',
            self::WebhooksWrite => 'Gérer les adresses webhook',
            self::PayoutsRead => 'Lire les reversements',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
