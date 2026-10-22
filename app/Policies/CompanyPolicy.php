<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Company;
use App\Models\User;

/**
 * Gestion des entreprises réservée au super administrateur (Gate::before).
 * Un administrateur peut consulter et modifier sa propre entreprise.
 */
class CompanyPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::CompaniesManage->value);
    }

    public function view(User $actor, Company $company): bool
    {
        return $actor->company_id === $company->id;
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::CompaniesManage->value);
    }

    public function update(User $actor, Company $company): bool
    {
        return $actor->company_id === $company->id
            && $actor->can(Permission::SettingsManage->value);
    }

    public function changeStatus(User $actor, Company $company): bool
    {
        return $actor->can(Permission::CompaniesManage->value);
    }
}
