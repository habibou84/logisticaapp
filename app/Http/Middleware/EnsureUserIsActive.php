<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloque les comptes suspendus (ou dont l'entreprise est suspendue),
 * même s'ils possèdent encore un jeton valide. Sur la plateforme, un compte
 * ne s'utilise qu'à l'adresse de son entreprise (la console : super administrateur).
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

        if ($user !== null && ! self::allowedHere($user)) {
            return response()->json(['message' => 'Ce compte n\'appartient pas à cette entreprise.'], 401);
        }

        return $next($request);
    }

    /**
     * Compte utilisable à cette adresse : son entreprise, ou la console pour le super administrateur.
     */
    public static function allowedHere($user): bool
    {
        $tenancy = app(Tenancy::class);

        return match ($tenancy->zone()) {
            Tenancy::COMPANY => $user->company_id !== null && $user->company_id === $tenancy->company()?->id,
            Tenancy::CONSOLE => $user->hasRole(Role::SuperAdmin->value),
            default => true,
        };
    }
}
