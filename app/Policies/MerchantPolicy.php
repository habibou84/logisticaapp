<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Departments\DepartmentSettings;

class MerchantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::MerchantsView->value) || $user->can(Permission::MerchantsManage->value);
    }

    public function view(User $user, Merchant $merchant): bool
    {
        return $user->merchant_id === $merchant->id || $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::MerchantsManage->value);
    }

    public function update(User $user, Merchant $merchant): bool
    {
        return $user->can(Permission::MerchantsManage->value);
    }

    /**
     * Départements du client : le personnel autorisé, ou le gérant du client si l'administrateur le permet.
     */
    public function manageDepartments(User $user, Merchant $merchant): bool
    {
        if ($user->merchant_id === null) {
            return $user->can(Permission::MerchantsManage->value);
        }

        return $user->merchant_id === $merchant->id && $user->hasRole(Role::MerchantOwner->value)
            && app(DepartmentSettings::class)->for($merchant)['client_manages'];
    }

    /**
     * Préférences de messages WhatsApp : le client lui-même ou le personnel autorisé.
     */
    public function manageNotifications(User $user, Merchant $merchant): bool
    {
        return $user->merchant_id === $merchant->id || $user->can(Permission::MerchantsManage->value);
    }
}
