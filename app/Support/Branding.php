<?php

namespace App\Support;

use App\Enums\CompanyStatus;
use App\Models\Company;

/**
 * Identité visuelle affichée sur les pages publiques (connexion, accueil des
 * e-commerçants) : l'entreprise de livraison qui exploite ce site.
 * BRANDING_COMPANY (slug ou identifiant) la désigne quand plusieurs entreprises
 * partagent l'installation ; sinon, la première entreprise active.
 */
class Branding
{
    public static function company(): ?Company
    {
        // Plateforme : l'entreprise de l'adresse (aucune sur la présentation ou la console)
        if (Tenancy::enabled()) {
            return app(Tenancy::class)->company();
        }

        $wanted = config('app.branding_company');

        $query = Company::query()->where('status', CompanyStatus::Active->value);

        if (filled($wanted)) {
            $company = (clone $query)->where(is_numeric($wanted) ? 'id' : 'slug', $wanted)->first();
            if ($company) {
                return $company;
            }
        }

        return $query->orderBy('id')->first();
    }

    public static function logoUrl(?Company $company): ?string
    {
        if (! $company?->logo_path) {
            return null;
        }

        // Le paramètre change avec le fichier : le navigateur peut le garder longtemps en cache
        return '/branding/logo?v='.substr(md5($company->logo_path), 0, 10);
    }

    /**
     * @return array<string, mixed>
     */
    public static function data(?Company $company): array
    {
        return [
            'name' => $company?->name ?? config('app.name'),
            'tagline' => $company?->tagline,
            'phone' => $company?->phone,
            'email' => $company?->email,
            'address' => $company?->address,
            'logo_url' => self::logoUrl($company),
            // Inscription en ligne des e-commerçants ouverte
            'signup_open' => (bool) $company?->merchant_signup,
            // Couleurs des espaces, noms et icônes des applications
            'appearance' => Appearance::for($company),
        ];
    }
}
