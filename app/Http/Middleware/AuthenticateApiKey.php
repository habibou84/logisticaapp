<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie l'API publique par clé (Authorization: Bearer lv_… ou X-Api-Key) ; la
 * requête agit ensuite au nom d'un compte du marchand,
 * avec exactement les mêmes règles que son application.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken() ?? $request->header('X-Api-Key');
        $key = $plain ? ApiKey::findValid(trim($plain)) : null;

        if ($key === null) {
            return $this->error('Clé API manquante, invalide, expirée ou révoquée.', 401);
        }

        $actor = $key->merchant?->isActive() ? $key->actor() : null;

        if ($actor === null || ! $actor->isActive()) {
            return $this->error('Le compte de ce marchand est suspendu ou n\'a plus d\'utilisateur actif.', 403);
        }

        Auth::setUser($actor);
        $request->setUserResolver(fn () => $actor);
        $request->attributes->set('api_key', $key);

        // Dernière utilisation, au plus une écriture par minute
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
