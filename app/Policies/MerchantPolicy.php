<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Merchant;
use App\Models\User;

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
     * Préférences de messages WhatsApp : le marchand lui-même ou le personnel autorisé.
     */
    public function manageNotifications(User $user, Merchant $merchant): bool
    {
        return $user->merchant_id === $merchant->id || $user->can(Permission::MerchantsManage->value);
    }
}
