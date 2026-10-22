<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Préproduction fermée au public : avant toute page, un code d'accès (PREPROD_ACCESS_CODE), saisi une fois
 * puis mémorisé 30 jours pour la plateforme, la console et toutes les entreprises de test. Restent ouverts :
 * les webhooks entrants (Meta), l'API publique (protégée par la clé du marchand : Jibiat Marchand de préproduction
 * s'y connecte) et la sonde /up. Jamais actif en production.
 */
class PreprodGate
{
    public const COOKIE = 'jibiat_preprod';

    public const PATH = '_acces-preproduction';

    /** Manifestes, icônes et logo des applications : rien de privé (nom, couleurs, images) */
    private const APP_FILES = ['manifest/*', 'branding/icon/*', 'branding/logo'];

    /** Préproduction protégée par le code d'accès (jamais en production) */
    public static function active(): bool
    {
        return (string) config('platform.preprod_access_code') !== '' && ! app()->isProduction();
    }

    public function handle(Request $request, Closure $next): Response
    {
        $code = (string) config('platform.preprod_access_code');
        // Fichiers publics des applications installables : le navigateur télécharge le manifeste et les
        // icônes sans cookie, la page du code rendrait l'application impossible à installer
        // Reprise de l'ancienne application : envoyée par son serveur, protégée par la clé de reprise
        if ($code === '' || app()->isProduction() || $request->is('up', 'api/webhooks/*', 'api/public/*', 'api/legacy-import', ...self::APP_FILES)) {
            return $next($request);
        }

        $pass = hash_hmac('sha256', $code, (string) config('app.key'));
        if (hash_equals($pass, (string) $request->cookies->get(self::COOKIE))) {
            return $next($request);
        }

        if ($request->is(self::PATH) && $request->isMethod('POST')) {
            return $this->check($request, $code, $pass);
        }
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'Préproduction : saisissez le code d\'accès.'], 403);
        }

        return $this->form($request->getRequestUri());
    }

    private function check(Request $request, string $code, string $pass): Response
    {
        $key = 'preprod-gate:'.$request->ip();
        $back = $this->back((string) $request->input('retour'));
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return $this->form($back, 'Trop d\'essais. Réessayez dans une minute.', 429);
        }
        if (! hash_equals($code, (string) $request->input('code'))) {
            RateLimiter::hit($key, 60);

            return $this->form($back, 'Code incorrect.', 403);
        }
        RateLimiter::clear($key);

        // Valable sur la plateforme, la console et toutes les entreprises de test (*.domaine), 30 jours
        $domain = Tenancy::domain();
        $cookieDomain = str_contains($domain, '.') && str_ends_with($request->getHost(), $domain) ? '.'.$domain : null;

        return redirect($back)->withCookie(Cookie::create(self::COOKIE, $pass, now()->addDays(30), '/', $cookieDomain, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));
    }

    // Retour seulement vers une page de ce site
    private function back(string $path): string
    {
        return str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_starts_with($path, '/'.self::PATH) ? $path : '/';
    }

    private function form(string $back, ?string $error = null, int $status = 401): Response
    {
        return response()->view('platform.preprod-gate', ['back' => $this->back($back), 'error' => $error, 'action' => '/'.self::PATH], $status)
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
