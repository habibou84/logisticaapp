<?php

namespace App\Services\Platform;

use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\User;
use App\Notifications\CustomDomainActivated;
use App\Support\Dns;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Domaine personnalisé d'une entreprise (ex. livraison.rapide-express.ci).
 *
 * Le client fait pointer son domaine (CNAME) vers PLATFORM_CUSTOM_DOMAIN_TARGET ;
 * Cloudflare for SaaS reçoit le trafic, gère le certificat et l'envoie au serveur
 * avec le nom du client. Le domaine devient « actif » quand la page de contrôle
 * répond en HTTPS avec l'adresse de cette entreprise : c'est la preuve que tout
 * le chemin fonctionne.
 */
class CustomDomains
{
    // Chemin servi sur toutes les adresses : renvoie l'entreprise reconnue
    public const CHECK_PATH = '/.well-known/domain-check';

    private const CLOUDFLARE_API = 'https://api.cloudflare.com/client/v4';

    public function __construct(private readonly Dns $dns) {}

    /**
     * « https://Livraison.Rapide-Express.ci/ » → « livraison.rapide-express.ci ».
     */
    public static function normalize(string $input): string
    {
        $host = strtolower(trim($input));
        $host = preg_replace('#^[a-z]+://#', '', $host);
        $host = preg_replace('#[/?\#].*$#', '', $host);
        $host = preg_replace('#:\d+$#', '', $host);

        return rtrim($host, '.');
    }

    /**
     * Enregistre le domaine (en attente de vérification) et prépare Cloudflare.
     */
    public function set(Company $company, string $input): Company
    {
        $domain = self::normalize($input);
        $this->validate($company, $domain);

        if ($company->custom_domain === $domain) {
            return $company;
        }

        $this->removeFromCloudflare($company);

        $company->forceFill([
            'custom_domain' => $domain,
            'custom_domain_status' => 'pending',
            'custom_domain_error' => null,
            'custom_domain_checked_at' => null,
            'cloudflare_hostname_id' => $this->addToCloudflare($domain),
        ])->save();

        return $company;
    }

    public function remove(Company $company): Company
    {
        $this->removeFromCloudflare($company);

        $company->forceFill([
            'custom_domain' => null,
            'custom_domain_status' => null,
            'custom_domain_error' => null,
            'custom_domain_checked_at' => null,
            'cloudflare_hostname_id' => null,
        ])->save();

        return $company;
    }

    /**
     * Vérifie le DNS et l'accès HTTPS ; active le domaine quand tout répond.
     *
     * @return array<string, mixed>
     */
    public function check(Company $company): array
    {
        if (! $company->custom_domain) {
            return $this->describe($company);
        }

        $domain = $company->custom_domain;
        $records = $this->dns->records($domain);
        $target = self::target();
        $dnsOk = ($target && in_array($target, $records['cname'], true))
            || (config('platform.custom_domains.server_ip') && in_array(config('platform.custom_domains.server_ip'), $records['a'], true));

        $httpsOk = false;
        $error = null;
        try {
            $response = Http::timeout(8)->acceptJson()->get('https://'.$domain.self::CHECK_PATH);
            $httpsOk = $response->successful() && $response->json('slug') === $company->slug;
            if (! $httpsOk) {
                $error = $response->successful()
                    ? 'Le domaine répond, mais pas avec votre espace : le serveur ne le reconnaît pas encore.'
                    : "Le domaine répond avec une erreur ({$response->status()}).";
            }
        } catch (Throwable) {
            $error = $records['cname'] === [] && $records['a'] === []
                ? 'Le domaine n\'existe pas encore dans le DNS. Ajoutez l\'enregistrement indiqué chez votre fournisseur de domaine (la mise à jour peut prendre jusqu\'à quelques heures).'
                : 'Le domaine ne répond pas encore en HTTPS (certificat en cours de création ou DNS en cours de propagation).';
        }

        $wasActive = $company->custom_domain_status === 'active';
        $company->forceFill([
            'custom_domain_status' => $httpsOk ? 'active' : ($wasActive ? 'active' : 'pending'),
            'custom_domain_error' => $httpsOk ? null : $error,
            'custom_domain_checked_at' => now(),
        ])->save();

        if ($httpsOk && ! $wasActive) {
            $this->notifyAdmins($company);
        }

        return [...$this->describe($company), 'dns' => ['ok' => $dnsOk, ...$records], 'https' => ['ok' => $httpsOk]];
    }

    /**
     * État et instructions à afficher dans les paramètres de l'entreprise.
     *
     * @return array<string, mixed>
     */
    public function describe(Company $company): array
    {
        return [
            'platform_url' => Tenancy::enabled() ? config('platform.scheme').'://'.$company->slug.'.'.Tenancy::domain() : null,
            'custom_domain' => $company->custom_domain,
            'status' => $company->custom_domain_status,
            'error' => $company->custom_domain_error,
            'checked_at' => $company->custom_domain_checked_at,
            'url' => $company->url(),
            'available' => Tenancy::enabled() && self::target() !== null,
            'dns_record' => $company->custom_domain && self::target()
                ? ['type' => 'CNAME', 'name' => $company->custom_domain, 'value' => self::target()]
                : null,
        ];
    }

    /**
     * Nom vers lequel les clients font pointer leur domaine (ex. clients.jibiat.com).
     */
    public static function target(): ?string
    {
        $target = config('platform.custom_domains.target');

        return filled($target) ? strtolower(rtrim($target, '.')) : null;
    }

    private function validate(Company $company, string $domain): void
    {
        $platform = Tenancy::domain();

        $message = match (true) {
            ! preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) => 'Adresse invalide. Exemple : livraison.votre-entreprise.ci',
            $platform !== '' && ($domain === $platform || str_ends_with($domain, '.'.$platform)) => "Choisissez une adresse qui vous appartient, pas une adresse en .{$platform}.",
            Company::query()->where('custom_domain', $domain)->whereKeyNot($company->id)->exists() => 'Cette adresse est déjà utilisée par une autre entreprise.',
            default => null,
        };

        if ($message) {
            throw new BusinessRuleException($message, 'domain');
        }
    }

    private function cloudflareConfigured(): bool
    {
        return filled(config('platform.custom_domains.cloudflare.token')) && filled(config('platform.custom_domains.cloudflare.zone_id'));
    }

    /**
     * Crée le nom d'hôte chez Cloudflare for SaaS (certificat validé par HTTP).
     */
    private function addToCloudflare(string $domain): ?string
    {
        if (! $this->cloudflareConfigured()) {
            return null;
        }

        $response = Http::withToken(config('platform.custom_domains.cloudflare.token'))->timeout(15)
            ->post(self::CLOUDFLARE_API.'/zones/'.config('platform.custom_domains.cloudflare.zone_id').'/custom_hostnames', [
                'hostname' => $domain,
                'ssl' => ['method' => 'http', 'type' => 'dv'],
            ]);

        if (! $response->successful()) {
            Log::warning('Cloudflare : nom d\'hôte non créé', ['domain' => $domain, 'errors' => $response->json('errors')]);

            throw new BusinessRuleException('Le domaine n\'a pas pu être préparé chez notre hébergeur. Réessayez dans un instant ou contactez-nous.', 'domain');
        }

        return $response->json('result.id');
    }

    private function removeFromCloudflare(Company $company): void
    {
        if (! $company->cloudflare_hostname_id || ! $this->cloudflareConfigured()) {
            return;
        }

        $response = Http::withToken(config('platform.custom_domains.cloudflare.token'))->timeout(15)
            ->delete(self::CLOUDFLARE_API.'/zones/'.config('platform.custom_domains.cloudflare.zone_id').'/custom_hostnames/'.$company->cloudflare_hostname_id);

        if (! $response->successful() && $response->status() !== 404) {
            Log::warning('Cloudflare : nom d\'hôte non supprimé', ['id' => $company->cloudflare_hostname_id, 'errors' => $response->json('errors')]);
        }
    }

    private function notifyAdmins(Company $company): void
    {
        $admins = User::query()->where('company_id', $company->id)->whereNull('merchant_id')
            ->where('status', 'active')->role(Role::Admin->value)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new CustomDomainActivated($company));
        }
    }
}
