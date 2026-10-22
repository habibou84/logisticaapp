<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hors production (préproduction, essais), toutes les réponses demandent aux moteurs de recherche de ne pas
 * indexer le site (voir aussi /robots.txt). SEARCH_INDEXING force le réglage.
 */
class PreventIndexing
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('platform.indexable')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
