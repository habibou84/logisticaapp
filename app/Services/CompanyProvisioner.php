<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PayPlan;
use App\Models\PricingGrid;
use App\Models\Zone;

/**
 * Prépare une nouvelle entreprise : communes d'Abidjan, grille tarifaire et plan de
 * rémunération des livreurs par défaut (vides).
 */
class CompanyProvisioner
{
    public const ABIDJAN_COMMUNES = [
        'Abobo', 'Adjamé', 'Anyama', 'Attécoubé', 'Bingerville', 'Cocody', 'Koumassi',
        'Marcory', 'Plateau', 'Port-Bouët', 'Songon', 'Treichville', 'Yopougon',
    ];

    public function provision(Company $company): void
    {
        foreach (self::ABIDJAN_COMMUNES as $i => $name) {
            Zone::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'parent_id' => null, 'name' => $name],
                ['city' => 'Abidjan', 'sort_order' => $i],
            );
        }

        // Plan de rémunération des livreurs par défaut (vide : à compléter dans Paie des livreurs)
        if (PayPlan::defaultFor($company->id) === null) {
            PayPlan::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Plan standard', 'is_default' => true]);
        }

        if (! PricingGrid::withoutGlobalScopes()->where('company_id', $company->id)->where('is_default', true)->exists()) {
            PricingGrid::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'name' => 'Grille standard',
                'is_default' => true,
            ]);
        }
    }
}
