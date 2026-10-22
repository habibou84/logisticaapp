<?php

namespace App\Services\Webhooks;

use App\Exceptions\BusinessRuleException;

/**
 * Empêche d'utiliser les webhooks pour atteindre le réseau interne (SSRF) :
 * HTTPS obligatoire en production, et jamais d'adresse privée, locale ou réservée.
 */
class UrlGuard
{
    /**
     * Contrôle à l'enregistrement de l'adresse.
     */
    public static function validate(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, self::allowedSchemes(), true) || $host === '') {
            throw new BusinessRuleException('L\'adresse doit commencer par https://.', 'url');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new BusinessRuleException('L\'adresse ne doit pas contenir d\'identifiants.', 'url');
        }

        if (! self::allowPrivate() && ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')
            || (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) && ! self::isPublicIp(trim($host, '[]'))))) {
            throw new BusinessRuleException('Cette adresse n\'est pas joignable depuis Internet.', 'url');
        }
    }

    /**
     * Contrôle au moment de l'envoi : le nom doit se résoudre uniquement vers des adresses publiques.
     *
     * @return string|null message d'erreur, ou null si l'envoi est autorisé
     */
    public static function blockedReason(string $url): ?string
    {
        try {
            self::validate($url);
        } catch (BusinessRuleException $e) {
            return $e->getMessage();
        }

        if (self::allowPrivate()) {
            return null;
        }

        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            return 'Nom de domaine introuvable.';
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return 'L\'adresse pointe vers un réseau privé.';
            }
        }

        return null;
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * @return list<string>
     */
    private static function allowedSchemes(): array
    {
        return config('services.webhooks.allow_http') ? ['https', 'http'] : ['https'];
    }

    private static function allowPrivate(): bool
    {
        return (bool) config('services.webhooks.allow_private_targets');
    }
}
