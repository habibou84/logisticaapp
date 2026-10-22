<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vérifie qu'une clé API (déjà authentifiée par AuthenticateApiKey) a la portée demandée.
 */
class EnsureApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        /** @var ApiKey|null $key */
        $key = $request->attributes->get('api_key');

        if (! $key?->allows($scope)) {
            return response()->json(['message' => "Cette clé n'a pas la portée « {$scope} »."], 403);
        }

        return $next($request);
    }
}
