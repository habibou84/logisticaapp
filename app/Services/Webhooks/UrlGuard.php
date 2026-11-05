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
        return self::target($url)['reason'];
    }

    /**
     * Contrôle au moment de l'envoi, avec l'adresse IP à utiliser : l'envoi se fait vers cette adresse précise
     * (pas de seconde résolution du nom, qui pourrait renvoyer une adresse interne).
     *
     * @return array{reason: ?string, ip: ?string}
     */
    public static function target(string $url): array
    {
        try {
            self::validate($url);
        } catch (BusinessRuleException $e) {
            return ['reason' => $e->getMessage(), 'ip' => null];
        }

        if (self::allowPrivate()) {
            return ['reason' => null, 'ip' => null];
        }

        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);

        if ($ips === []) {
            return ['reason' => 'Nom de domaine introuvable.', 'ip' => null];
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return ['reason' => 'L\'adresse pointe vers un réseau privé.', 'ip' => null];
            }
        }

        return ['reason' => null, 'ip' => $ips[0]];
    }

    /**
     * Adresses IPv4 et IPv6 du nom.
     *
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = array_values(array_filter(array_map(fn (array $r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));

        return $ips !== [] ? $ips : (gethostbynamel($host) ?: []);
    }

    /**
     * Adresse publique : ni privée, ni réservée, ni partagée (CGNAT), ni IPv4 cachée dans une IPv6
     * (::ffff:127.0.0.1, 64:ff9b::7f00:1, 2002:7f00:1::).
     */
    public static function isPublicIp(string $ip): bool
    {
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return false;
        }

        if (strlen($binary) === 16) {
            $prefix = bin2hex(substr($binary, 0, 12));
            // IPv4 mappée (::ffff:a.b.c.d) ou compatible (::a.b.c.d), NAT64 (64:ff9b::/96) : on juge l'IPv4
            if ($prefix === '00000000000000000000ffff' || $prefix === '000000000000000000000000' || $prefix === '0064ff9b0000000000000000') {
                return self::isPublicIp((string) inet_ntop(substr($binary, 12)));
            }
            // 6to4 (2002::/16) et Teredo (2001:0::/32) transportent une IPv4 : refusés
            $first = bin2hex(substr($binary, 0, 4));
            if (str_starts_with($first, '2002') || $first === '20010000') {
                return false;
            }
        } else {
            [$a, $b] = [ord($binary[0]), ord($binary[1])];
            // 0/8, 100.64/10 (CGNAT), 192.0.0/24, 198.18/15 (tests) : absents des filtres de PHP
            if ($a === 0 || ($a === 100 && $b >= 64 && $b <= 127) || ($a === 192 && $b === 0 && ord($binary[2]) === 0) || ($a === 198 && ($b === 18 || $b === 19))) {
                return false;
            }
        }

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
