<?php

namespace App\Enums;

enum Permission: string
{
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case SettingsManage = 'settings.manage';
    case MerchantsView = 'merchants.view';
    case MerchantsManage = 'merchants.manage';
    case OrdersView = 'orders.view';
    case OrdersCreate = 'orders.create';
    case OrdersDispatch = 'orders.dispatch';
    case OrdersUpdateStatus = 'orders.update_status';
    case FinanceView = 'finance.view';
    case FinanceManage = 'finance.manage';
    case StockManage = 'stock.manage';
    // Clés API et webhooks
    case IntegrationsManage = 'integrations.manage';
    // Factures du mois des clients (distribution, MAD) : consulter ; calculer, émettre, encaisser
    case InvoicesView = 'invoices.view';
    case InvoicesManage = 'invoices.manage';
    // Formules des clients et tarifs de la distribution et de l'intérieur
    case RatesManage = 'rates.manage';
    // Chiffre d'affaires par activité
    case RevenueView = 'revenue.view';

    public function label(): string
    {
        return match ($this) {
            self::UsersView => 'Voir l\'équipe',
            self::UsersManage => 'Gérer l\'équipe',
            self::SettingsManage => 'Réglages de l\'entreprise',
            self::MerchantsView => 'Voir les clients',
            self::MerchantsManage => 'Gérer les clients',
            self::OrdersView => 'Voir les courses',
            self::OrdersCreate => 'Créer des courses',
            self::OrdersDispatch => 'Dispatch (assigner les livreurs)',
            self::OrdersUpdateStatus => 'Changer l\'état des courses',
            self::FinanceView => 'Voir la caisse et les reversements',
            self::FinanceManage => 'Encaisser et reverser',
            self::StockManage => 'Gérer le stock',
            self::IntegrationsManage => 'Clés d\'API et webhooks',
            self::InvoicesView => 'Voir les factures clients',
            self::InvoicesManage => 'Calculer, émettre et encaisser les factures',
            self::RatesManage => 'Formules et tarifs (distribution, intérieur)',
            self::RevenueView => 'Voir le chiffre d\'affaires',
        };
    }

    /**
     * Toutes les permissions de l'entreprise (rôle administrateur).
     *
     * @return list<self>
     */
    public static function companyScoped(): array
    {
        return self::cases();
    }
}
