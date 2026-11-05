<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
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

    /**
     * Identifiants de connexion (téléphone, e-mail, mot de passe, lien d'invitation) : pas ceux d'un autre
     * administrateur, sinon un administrateur pourrait prendre le compte d'un autre.
     */
    public function changeCredentials(User $actor, User $user): bool
    {
        return $this->update($actor, $user)
            && ($actor->is($user) || ! $user->hasRole(Role::Admin->value));
    }

    /**
     * Rôle d'un autre administrateur : pas modifiable. Le rétrograder puis changer ses identifiants reviendrait à
     * prendre son compte. (Le suspendre reste possible : un administrateur qui quitte l'entreprise.)
     */
    public function changeRole(User $actor, User $user): bool
    {
        return $this->changeRoleOrStatus($actor, $user) && ! $user->hasRole(Role::Admin->value);
    }

    public function delete(User $actor, User $user): bool
    {
        return ! $actor->is($user) && $this->update($actor, $user)
            && ! app(Access::class)->isLastAdmin($user)
            && ($user->accessRole() === null || $user->merchant_id !== null || app(Access::class)->canAssign($actor, $user->accessRole()));
    }

    /**
     * Un utilisateur ne peut pas modifier son propre rôle ni son propre statut (évite qu'un admin se bloque
     * lui-même ou s'attribue un rôle), ni ceux d'un autre administrateur.
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
