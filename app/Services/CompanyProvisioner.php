<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PayPlan;
use App\Models\PricingGrid;
use App\Services\Zones\StandardZones;

/**
 * Prépare une nouvelle entreprise : zones de la liste des lieux (communes et quartiers d'Abidjan, villes), grille
 * tarifaire et plan de rémunération des livreurs par défaut (vides).
 */
class CompanyProvisioner
{
    /** Communes couvertes par les forfaits (distribution, MAD) */
    public const FORFAIT_COMMUNES = ['Abobo', 'Adjamé', 'Attécoubé', 'Cocody', 'Koumassi', 'Marcory', 'Plateau', 'Port-Bouët', 'Treichville', 'Yopougon'];

    public function provision(Company $company): void
    {
        // Communes et quartiers d'Abidjan, villes de l'intérieur (masquées tant qu'elles n'ont pas de tarif)
        app(StandardZones::class)->sync($company);

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
