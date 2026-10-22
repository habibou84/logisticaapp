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
