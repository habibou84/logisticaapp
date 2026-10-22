<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloque les comptes suspendus (ou dont l'entreprise est suspendue),
 * même s'ils possèdent encore un jeton valide.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->isActive()) {
            $token = $user->currentAccessToken();

            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            return response()->json(['message' => 'Compte suspendu. Contactez votre administrateur.'], 403);
        }

        return $next($request);
    }
}
