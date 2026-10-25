<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Company;
use App\Models\User;

/**
 * L'entreprise : consultée par son équipe ; ses réglages modifiés selon leur domaine (voir FIELDS), la marque
 * (nom, logo, apparence) par les réglages généraux.
 */
class CompanyPolicy
{
    /**
     * Réglages enregistrés sur l'entreprise qui relèvent d'une permission métier ; les autres : réglages généraux.
     *
     * @var array<string, Permission>
     */
    public const FIELDS = [
        'return_fee_percent' => Permission::PricingManage,
        'vehicle_settings' => Permission::FleetManage,
        'field_event_settings' => Permission::FieldEventsManage,
        'rating_settings' => Permission::RatingsManage,
        'department_settings' => Permission::DepartmentsManage,
        'destination_settings' => Permission::DestinationsManage,
    ];

    public function view(User $actor, Company $company): bool
    {
        return $actor->company_id === $company->id;
    }

    /** Au moins un réglage modifiable : le détail est vérifié champ par champ (permissionFor) */
    public function update(User $actor, Company $company): bool
    {
        return $actor->company_id === $company->id
            && $actor->canAny(array_map(fn (Permission $p) => $p->value, [Permission::SettingsManage, ...array_values(self::FIELDS)]));
    }

    /** Nom, logo, apparence des espaces */
    public function manageBrand(User $actor, Company $company): bool
    {
        return $actor->company_id === $company->id && $actor->can(Permission::SettingsManage->value);
    }

    public static function permissionFor(string $field): Permission
    {
        return self::FIELDS[$field] ?? Permission::SettingsManage;
    }
}
