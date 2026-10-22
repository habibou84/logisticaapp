<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Company;
use App\Models\User;

/**
 * Un administrateur consulte et modifie son entreprise.
 */
class CompanyPolicy
{
    public function view(User $actor, Company $company): bool
    {
        return $actor->company_id === $company->id;
    }

    public function update(User $actor, Company $company): bool
    {
        return $actor->company_id === $company->id
            && $actor->can(Permission::SettingsManage->value);
    }
}
