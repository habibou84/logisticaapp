<?php

namespace App\Http\Middleware;

use App\Enums\PlatformFeature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module inclus dans la formule de l'entreprise de l'utilisateur (au moins un des modules
 * cités). Le super administrateur et les entreprises sans formule ont tout.
 *
 *   ->middleware('feature:stock,shop')
 */
class EnsureFeature
{
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        $company = $request->user()?->loadMissing('company')->company;
        $wanted = array_map(fn (string $f) => PlatformFeature::from($f), $features);

        if ($company === null || collect($wanted)->contains(fn (PlatformFeature $f) => $company->hasFeature($f))) {
            return $next($request);
        }

        return response()->json([
            'message' => '« '.$wanted[0]->label().' » n\'est pas inclus dans la formule de votre entreprise. Contactez-nous pour changer de formule.',
            'feature' => $wanted[0]->value,
        ], 403);
    }
}
