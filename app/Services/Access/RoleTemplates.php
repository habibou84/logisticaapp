<?php

namespace App\Services\Access;

use App\Enums\Permission as P;
use App\Enums\Role;

/**
 * Modèles de départ d'un rôle sur mesure : les rôles intégrés, et des rôles courants prêts à l'emploi. Le rôle créé
 * en garde une copie des permissions, que l'administrateur ajuste.
 */
class RoleTemplates
{
    /**
     * @return list<array{key: string, label: string, description: string, permissions: list<string>, scope_hint: ?string}>
     */
    public static function all(): array
    {
        $ready = [
            ['key' => 'accountant', 'label' => 'Comptable', 'description' => 'Caisse, factures des clients et chiffre d\'affaires, sans toucher aux courses.',
                'permissions' => [P::OrdersView, P::MerchantsView, P::FinanceView, P::InvoicesView, P::InvoicesManage, P::RevenueView], 'scope_hint' => null],
            ['key' => 'fleet_manager', 'label' => 'Responsable flotte', 'description' => 'Véhicules, papiers, entretien, événements terrain et fiches des livreurs.',
                'permissions' => [P::OrdersView, P::CouriersManage, P::FleetManage, P::FieldEventsManage], 'scope_hint' => null],
            ['key' => 'customer_service', 'label' => 'Service client', 'description' => 'Suivre et créer les courses des clients, les annuler, tenir l\'annuaire.',
                'permissions' => [P::OrdersView, P::OrdersCreate, P::OrdersCancel, P::MerchantsView, P::DestinationsManage], 'scope_hint' => null],
            ['key' => 'sales', 'label' => 'Commercial', 'description' => 'Clients, contrats et tarifs négociés, départements, chiffre d\'affaires.',
                'permissions' => [P::OrdersView, P::MerchantsView, P::MerchantsManage, P::RatesManage, P::DepartmentsManage, P::RevenueView], 'scope_hint' => null],
            ['key' => 'hub_lead', 'label' => 'Chef d\'équipe d\'un dépôt', 'description' => 'Dispatch, réception et stock d\'un dépôt ; limitez-le à son dépôt ou à ses zones.',
                'permissions' => [P::OrdersView, P::OrdersCreate, P::OrdersDispatch, P::OrdersUpdateStatus, P::PlanningManage, P::MerchantsView, P::StockManage],
                'scope_hint' => 'hubs'],
        ];

        $builtIn = array_map(fn (Role $r) => [
            'key' => 'role:'.$r->value, 'label' => $r->label(), 'description' => 'Copie du rôle intégré « '.$r->label().' ».',
            'permissions' => $r->permissions(), 'scope_hint' => null,
        ], [Role::ActivityManager, Role::Dispatcher, Role::Cashier, Role::HubAgent]);

        return array_map(fn (array $t) => [...$t, 'permissions' => array_map(fn (P $p) => $p->value, $t['permissions'])], [...$ready, ...$builtIn]);
    }

    public static function find(?string $key): ?array
    {
        return collect(self::all())->firstWhere('key', $key);
    }
}
