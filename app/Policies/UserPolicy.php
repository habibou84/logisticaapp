<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Services\Access\Access;

/**
 * Le super administrateur passe toutes les vérifications (Gate::before).
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::UsersView->value);
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->is($user)
            || ($actor->belongsToSameCompanyAs($user) && $actor->can(Permission::UsersView->value));
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::UsersManage->value);
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->belongsToSameCompanyAs($user)
            && $actor->can(Permission::UsersManage->value);
    }

    public function delete(User $actor, User $user): bool
    {
        return ! $actor->is($user) && $this->update($actor, $user)
            && ! app(Access::class)->isLastAdmin($user)
            && ($user->accessRole() === null || $user->merchant_id !== null || app(Access::class)->canAssign($actor, $user->accessRole()));
    }

    /**
     * Un utilisateur ne peut pas modifier son propre rôle ni son propre statut
     * (évite qu'un admin se bloque lui-même ou s'attribue un rôle).
     */
    public function changeRoleOrStatus(User $actor, User $user): bool
    {
        // Jamais un compte plus puissant que soi (son rôle doit être de ceux qu'on peut attribuer)
        $role = $user->accessRole();

        return ! $actor->is($user)
            && $this->update($actor, $user)
            && $user->merchant_id === null
            && ($role === null || app(Access::class)->canAssign($actor, $role));
    }
}
