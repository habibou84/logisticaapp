<?php

namespace App\Enums;

/**
 * Modules inclus ou non selon la formule d'abonnement de l'entreprise.
 */
enum PlatformFeature: string
{
    case WhatsAppBot = 'whatsapp_bot';
    case Api = 'api';
    case Stock = 'stock';
    case Shop = 'shop';
    case AdvancedStats = 'advanced_stats';
    case CustomDomain = 'custom_domain';

    public function label(): string
    {
        return match ($this) {
            self::WhatsAppBot => 'Commandes par WhatsApp',
            self::Api => 'API et webhooks',
            self::Stock => 'Stock et entrepôt',
            self::Shop => 'Boutiques en ligne des marchands',
            self::AdvancedStats => 'Statistiques avancées',
            self::CustomDomain => 'Domaine personnalisé',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::WhatsAppBot => 'Les marchands créent leurs courses en écrivant au numéro WhatsApp de l\'entreprise.',
            self::Api => 'Clés d\'API et webhooks pour relier les sites des marchands (WooCommerce…).',
            self::Stock => 'Produits des marchands stockés à l\'entrepôt, mouvements et facturation du stockage.',
            self::Shop => 'Page de commande publique de chaque marchand (catalogue, panier, livraison).',
            self::AdvancedStats => 'Statistiques par zone et finances (revenus, dépenses, marge), export des tableaux vers Excel.',
            self::CustomDomain => 'L\'entreprise utilise sa propre adresse (ex. livraison.son-entreprise.ci) pour son espace, ses marchands, ses livreurs et ses liens de suivi.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
