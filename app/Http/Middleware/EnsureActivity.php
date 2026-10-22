<?php

namespace App\Http\Middleware;

use App\Enums\Activity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Écran propre à une activité (ex. reversements de l'e-commerce) : réservé à l'équipe qui la gère.
 *
 *   ->middleware('activity:ecommerce')
 */
class EnsureActivity
{
    public function handle(Request $request, Closure $next, string $activity): Response
    {
        $wanted = Activity::from($activity);

        if ($request->user() !== null && ! $request->user()->seesActivity($wanted)) {
            return response()->json(['message' => "Réservé à l'équipe de l'activité {$wanted->label()}."], 403);
        }

        return $next($request);
    }
}
