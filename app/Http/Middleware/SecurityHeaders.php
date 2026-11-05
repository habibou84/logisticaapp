<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité sur toutes les réponses : pas d'affichage dans le cadre d'un autre site (clickjacking), pas de
 * devinette du type de fichier, adresse d'origine réduite pour les liens sortants, HTTPS imposé au navigateur en
 * production. Un en-tête déjà posé (par Nginx ou une route) est gardé.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $defaults = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // Caméra (photos de preuve) et position (livreurs) : seulement pour l'application elle-même
            'Permissions-Policy' => 'camera=(self), geolocation=(self), microphone=(), payment=()',
        ];
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
}
