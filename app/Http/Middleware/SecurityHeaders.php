<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité sur toutes les réponses : pas d'affichage dans le cadre d'un autre site (clickjacking), pas de
 * devinette du type de fichier, adresse d'origine réduite pour les liens sortants, HTTPS imposé au navigateur en
 * production. Un en-tête déjà posé (par Nginx ou une route) est gardé.
 *
 * Politique de contenu (CSP) en mode « signalement seul » : rien n'est bloqué, les navigateurs signalent à
 * /api/csp-report ce qu'une politique stricte aurait refusé (journal storage/logs/csp-*.log). Une fois les rapports
 * vides, elle passe en blocage : une faille XSS ne pourra plus charger de script ni envoyer le jeton ailleurs.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Jeton unique par page : les balises de Vite (@vite) et nos scripts en ligne le portent
        Vite::useCspNonce();

        $response = $next($request);
        $headers = $response->headers;

        $defaults = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // Caméra (photos de preuve) et position (livreurs) : seulement pour l'application elle-même
            'Permissions-Policy' => 'camera=(self), geolocation=(self), microphone=(), payment=()',
        ];
        // Pas en local : le serveur Vite de développement (localhost:5173) remplirait le journal pour rien
        if (! app()->isLocal()) {
            $defaults['Content-Security-Policy-Report-Only'] = self::policy(Vite::cspNonce());
        }
        if ($request->isSecure() && app()->isProduction()) {
            $defaults['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($defaults as $name => $value) {
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * Politique visée : nos fichiers, plus ce dont l'application a besoin (connexion Facebook, documentation de l'API,
     * polices, tuiles de carte, Reverb). Les styles en ligne restent permis (Vue et Leaflet en posent).
     */
    public static function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' https://connect.facebook.net https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data: https://fonts.bunny.net",
            "connect-src 'self' https: wss:",
            "media-src 'self' blob:",
            "worker-src 'self' blob:",
            "frame-src 'self' https://www.facebook.com https://web.facebook.com",
            "frame-ancestors 'self'",
            "form-action 'self' https:",
            "object-src 'none'",
            "base-uri 'self'",
            'report-uri /api/csp-report',
        ]);
    }
}
