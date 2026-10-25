<?php

namespace App\Services\Access;

use App\Enums\Activity;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\AccessRole;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rôles de l'équipe et garde-fous : qui peut attribuer quoi, rôles sur mesure, dernier administrateur.
 *
 * Règle d'or : personne ne donne un droit qu'il n'a pas lui-même (l'administrateur a tous les droits), et seul un
 * administrateur fait un administrateur.
 */
class Access
{
    public function isAdmin(User $user): bool
    {
        return $user->hasRole(Role::Admin->value);
    }

    /**
     * Rôles que ce compte peut attribuer : rôles intégrés de l'équipe, livreur, rôles sur mesure de l'entreprise.
     *
     * @return Collection<int, AccessRole>
     */
    public function assignableRoles(User $actor): Collection
    {
        if (! $actor->can(Permission::UsersManage->value)) {
            return collect();
        }
        $builtIn = Role::values([...Role::staff(), Role::Courier]);
        $roles = AccessRole::query()->with('permissions')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('is_custom', false)->whereIn('name', $builtIn))
                ->orWhere(fn ($q) => $q->customFor($actor->company_id)))
            ->get()
            ->sortBy(fn (AccessRole $r) => [$r->is_custom ? 1 : 0, $r->is_custom ? $r->label : array_search($r->name, $builtIn, true)])
            ->values();

        return $roles->filter(fn (AccessRole $r) => $this->canAssign($actor, $r))->values();
    }

    public function canAssign(User $actor, AccessRole $role): bool
    {
        if ($role->is_custom && $role->company_id !== $actor->company_id) {
            return false;
        }
        if ($this->isAdmin($actor)) {
            return true;
        }

        return $role->name !== Role::Admin->value
            && $this->canGrant($actor, $role->permissions->pluck('name')->all());
    }

    /** @param  list<string>  $permissions */
    public function canGrant(User $actor, array $permissions): bool
    {
        return $this->isAdmin($actor) || array_diff($permissions, $actor->effectivePermissions()) === [];
    }

    /** @param  list<string>  $permissions */
    public function assertCanGrant(User $actor, array $permissions, string $field = 'permissions'): void
    {
        $missing = $this->isAdmin($actor) ? [] : array_values(array_diff($permissions, $actor->effectivePermissions()));
        if ($missing !== []) {
            $labels = array_map(fn (string $p) => Permission::tryFrom($p)?->label() ?? $p, $missing);
            throw ValidationException::withMessages([$field => 'Vous ne pouvez pas donner un droit que vous n\'avez pas : '.implode(', ', $labels).'.']);
        }
    }

    /**
     * Le compte est-il le dernier administrateur actif de l'entreprise ? (on ne peut alors ni lui retirer ce rôle,
     * ni le suspendre, ni le supprimer)
     */
    public function isLastAdmin(User $user): bool
    {
        if (! $this->isAdmin($user) || $user->status !== UserStatus::Active) {
            return false;
        }

        return User::query()->forCompany($user->company_id)->where('id', '!=', $user->id)
            ->where('status', UserStatus::Active->value)->role(Role::Admin->value)->doesntExist();
    }

    /**
     * Créer ou modifier un rôle sur mesure.
     *
     * @param  array{label: string, description?: ?string, template?: ?string, permissions: list<string>, activities?: ?list<string>, zone_ids?: ?list<int>, hub_ids?: ?list<int>}  $data
     */
    public function saveCustom(User $actor, array $data, ?AccessRole $role = null): AccessRole
    {
        $this->assertCanGrant($actor, $data['permissions']);
        if ($role !== null && ! $this->isAdmin($actor)) {
            // Modifier un rôle, c'est aussi pouvoir retirer ses droits : il faut déjà les avoir tous
            $this->assertCanGrant($actor, $role->permissions->pluck('name')->all());
        }

        $role ??= new AccessRole(['name' => 'custom_'.Str::lower(Str::random(12)), 'guard_name' => 'web']);
        $role->forceFill([
            'company_id' => $actor->company_id,
            'is_custom' => true,
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'template' => $data['template'] ?? $role->template,
            'activities' => array_values(array_intersect(Activity::values(), $data['activities'] ?? [])) ?: null,
            'zone_ids' => array_values(array_map('intval', $data['zone_ids'] ?? [])) ?: null,
            'hub_ids' => array_values(array_map('intval', $data['hub_ids'] ?? [])) ?: null,
        ])->save();
        $role->syncPermissions($data['permissions']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role->load('permissions');
    }

    /** @return array<string, mixed> */
    public function summary(AccessRole $role, ?User $actor = null): array
    {
        $builtIn = $role->builtIn();

        return [
            'value' => $role->name,
            'label' => $role->displayLabel(),
            'description' => $role->is_custom ? $role->description : $this->builtInDescription($builtIn),
            'custom' => $role->is_custom,
            'template' => $role->template,
            'permissions' => $role->permissions->pluck('name')->values()->all(),
            'activities' => $role->activities ?? [],
            'zone_ids' => $role->zone_ids ?? [],
            'hub_ids' => $role->hub_ids ?? [],
            'users_count' => $role->users_count ?? null,
            'assignable' => $actor ? $this->canAssign($actor, $role) : null,
            // Rôle sur mesure : modifiable par qui a déjà tous ses droits
            'editable' => $role->is_custom && $actor !== null && $actor->can(Permission::UsersManage->value)
                && $this->canGrant($actor, $role->permissions->pluck('name')->all()),
        ];
    }

    private function builtInDescription(?Role $role): ?string
    {
        return match ($role) {
            Role::Admin => 'Tous les droits. Lui seul fait un administrateur.',
            Role::ActivityManager => 'Clients, courses, dispatch, contrats, factures et chiffre d\'affaires de ses activités.',
            Role::Dispatcher => 'Courses et dispatch, planning, véhicules, terrain.',
            Role::Cashier => 'Caisse, reversements, factures, chiffre d\'affaires.',
            Role::HubAgent => 'Réception au dépôt, préparation, stock.',
            Role::Courier => 'Application livreur : ses missions.',
            default => null,
        };
    }
}
