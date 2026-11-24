<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Rapports des navigateurs sur la politique de contenu (CSP, voir SecurityHeaders) : écrits dans le journal « csp »,
 * une seule fois par heure pour une même règle, une même ressource et une même page (pas de journal rempli par un
 * navigateur bavard ou un inconnu). Aucune donnée personnelle : l'adresse de la page est gardée sans paramètres.
 */
class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (strlen($request->getContent()) > 16_384) {
            return response()->noContent();
        }
        $report = json_decode($request->getContent(), true)['csp-report'] ?? null;
        if (! is_array($report)) {
            return response()->noContent();
        }

        $clean = fn ($v) => is_string($v) ? mb_substr(strtok($v, '?#') ?: $v, 0, 300) : null;
        $entry = [
            'directive' => $clean($report['effective-directive'] ?? $report['violated-directive'] ?? null),
            'blocked' => $clean($report['blocked-uri'] ?? null),
            'page' => $clean($report['document-uri'] ?? null),
            'source' => $clean($report['source-file'] ?? null),
            'line' => is_int($report['line-number'] ?? null) ? $report['line-number'] : null,
        ];

        if (Cache::add('csp-report:'.md5(json_encode([$entry['directive'], $entry['blocked'], $entry['page']])), 1, now()->addHour())) {
            Log::channel('csp')->warning('Contenu hors politique', $entry);
        }

        return response()->noContent();
    }
}
