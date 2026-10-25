<?php

namespace App\Enums;

use App\Models\User;

enum Role: string
{
    case Admin = 'admin';
    // Responsable d'une ou plusieurs activités (e-commerce, distribution, MAD) : clients, formules, tarifs,
    // courses, factures et chiffre d'affaires de son périmètre
    case ActivityManager = 'activity_manager';
    case Dispatcher = 'dispatcher';
    case Cashier = 'cashier';
    case HubAgent = 'hub_agent';
    case Courier = 'courier';
    case MerchantOwner = 'merchant_owner';
    case MerchantStaff = 'merchant_staff';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrateur',
            self::ActivityManager => 'Responsable d\'activité',
            self::Dispatcher => 'Dispatcher',
            self::Cashier => 'Caissier',
            self::HubAgent => 'Agent de dépôt',
            self::Courier => 'Livreur',
            self::MerchantOwner => 'Client',
            self::MerchantStaff => 'Employé du client',
        };
    }

    /**
     * Permissions accordées à chaque rôle (avec celles nées du découpage de ses permissions larges : un rôle garde
     * tout ce qu'il pouvait faire avant le découpage).
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        $base = $this->basePermissions();
        $values = array_map(fn (Permission $p) => $p->value, $base);
        foreach ($base as $permission) {
            array_push($values, ...(Permission::splits()[$permission->value] ?? []));
        }

        return array_values(array_filter(Permission::cases(), fn (Permission $p) => in_array($p->value, $values, true)));
    }

    /** @return list<Permission> */
    private function basePermissions(): array
    {
        return match ($this) {
            self::Admin => Permission::companyScoped(),
            self::ActivityManager => [
                Permission::UsersView,
                Permission::MerchantsView,
                Permission::MerchantsManage,
                Permission::OrdersView,
                Permission::OrdersCreate,
                Permission::OrdersDispatch,
                Permission::OrdersUpdateStatus,
                Permission::InvoicesView,
                Permission::InvoicesManage,
                Permission::RatesManage,
                Permission::RevenueView,
            ],
            self::Dispatcher => [
                Permission::UsersView,
                Permission::MerchantsView,
                Permission::OrdersView,
                Permission::OrdersCreate,
                Permission::OrdersDispatch,
            ],
            self::Cashier => [
                Permission::MerchantsView,
                Permission::OrdersView,
                Permission::FinanceView,
                Permission::FinanceManage,
                Permission::InvoicesView,
                Permission::InvoicesManage,
                Permission::RevenueView,
            ],
            self::HubAgent => [
                Permission::MerchantsView,
                Permission::OrdersView,
                Permission::OrdersUpdateStatus,
                Permission::StockManage,
            ],
            self::Courier => [
                Permission::OrdersUpdateStatus,
            ],
            self::MerchantOwner => [
                Permission::OrdersView,
                Permission::OrdersCreate,
                Permission::FinanceView,
                Permission::StockManage,
                Permission::IntegrationsManage,
            ],
            self::MerchantStaff => [
                Permission::OrdersView,
                Permission::OrdersCreate,
            ],
        };
    }

    /**
     * Rôles du personnel de l'entreprise de livraison (back-office).
     *
     * @return list<self>
     */
    public static function staff(): array
    {
        return [self::Admin, self::ActivityManager, self::Dispatcher, self::Cashier, self::HubAgent];
    }

    /**
     * Rôles qu'un utilisateur peut attribuer via la gestion des utilisateurs.
     * Les rôles clients seront attribués à la création des clients (phase 1).
     *
     * @return list<self>
     */
    public static function assignableBy(User $actor): array
    {
        if ($actor->hasRole(self::Admin->value)) {
            return [...self::staff(), self::Courier];
        }

        return [];
    }

    /**
     * @param  list<self>  $roles
     * @return list<string>
     */
    public static function values(array $roles): array
    {
        return array_map(fn (self $role) => $role->value, $roles);
    }
}
