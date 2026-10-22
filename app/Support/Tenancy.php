<?php

namespace App\Support;

use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Http\Request;

/**
 * Entreprise désignée par l'adresse du site. Sur la plateforme (PLATFORM_DOMAIN) :
 *  - entreprise1.jibiat.com → l'entreprise « entreprise1 » (zone « company ») ;
 *  - livraison.entreprise1.ci → la même, par son domaine personnalisé (zone « company ») ;
 *  - admin.jibiat.com → la console du super administrateur (zone « console ») ;
 *  - jibiat.com, www.jibiat.com → la présentation de la plateforme (zone « platform »).
 * Sans PLATFORM_DOMAIN : une seule entreprise (zone « single »).
 */
class Tenancy
{
    public const SINGLE = 'single';

    public const COMPANY = 'company';

    public const CONSOLE = 'console';

    public const PLATFORM = 'platform';

    private bool $resolved = false;

    private string $zone = self::SINGLE;

    private ?Company $company = null;

    // Adresse d'une entreprise inconnue ou suspendue
    private ?string $problem = null;

    public static function enabled(): bool
    {
        return filled(config('platform.domain'));
    }

    public static function domain(): string
    {
        return strtolower(ltrim((string) config('platform.domain'), '.'));
    }

    public function resolve(Request $request): void
    {
        $this->resolved = true;
        $this->company = null;
        $this->problem = null;

        if (! self::enabled()) {
            $this->zone = self::SINGLE;

            return;
        }

        $host = strtolower($request->getHost());
        $domain = self::domain();

        if ($host === $domain || $host === "www.{$domain}") {
            $this->zone = self::PLATFORM;

            return;
        }
        if ($host === config('platform.admin_subdomain').'.'.$domain) {
            $this->zone = self::CONSOLE;

            return;
        }

        $this->zone = self::COMPANY;
        if (str_ends_with($host, ".{$domain}")) {
            $slug = substr($host, 0, -strlen(".{$domain}"));
            $company = ! str_contains($slug, '.') ? Company::query()->where('slug', $slug)->first() : null;
        } else {
            // Domaine personnalisé de l'entreprise (avec ou sans « www. »)
            $company = Company::query()->whereIn('custom_domain', array_unique([$host, preg_replace('/^www\./', '', $host)]))->first();
        }

        if ($company === null) {
            $this->problem = 'unknown';
        } elseif ($company->status !== CompanyStatus::Active) {
            $this->problem = 'suspended';
        } else {
            $this->company = $company;
        }
    }

    public function zone(): string
    {
        $this->ensureResolved();

        return $this->zone;
    }

    /**
     * Entreprise de l'adresse (null : présentation, console, adresse inconnue ou suspendue).
     */
    public function company(): ?Company
    {
        $this->ensureResolved();

        return $this->company;
    }

    public function problem(): ?string
    {
        $this->ensureResolved();

        return $this->problem;
    }

    /**
     * Pages publiques (suivi, boutiques, photos) : seulement les données de l'entreprise de l'adresse.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder
     *
     * @param  T  $query
     * @return T
     */
    public function restrict($query, string $column = 'company_id')
    {
        return $this->zone() === self::COMPANY ? $query->where($column, $this->company()?->id ?? 0) : $query;
    }

    /**
     * Adresse de base d'une entreprise : son domaine personnalisé vérifié, sinon son
     * sous-domaine sur la plateforme ; sans plateforme, APP_URL.
     */
    /**
     * Adresse de la plateforme (jibiat.com) ; sans plateforme, l'adresse de l'application.
     */
    public static function platformUrl(): string
    {
        return self::enabled()
            ? config('platform.scheme').'://'.self::domain()
            : rtrim((string) config('app.url'), '/');
    }

    public static function baseUrl(Company $company): string
    {
        if (! self::enabled()) {
            return rtrim((string) config('app.url'), '/');
        }

        // Domaine personnalisé vérifié : c'est l'adresse donnée aux marchands et aux destinataires
        return $company->custom_domain && $company->custom_domain_status === 'active'
            ? config('platform.scheme').'://'.$company->custom_domain
            : config('platform.scheme').'://'.$company->slug.'.'.self::domain();
    }

    private function ensureResolved(): void
    {
        if (! $this->resolved && app()->bound('request')) {
            $this->resolve(app('request'));
        }
    }
}
