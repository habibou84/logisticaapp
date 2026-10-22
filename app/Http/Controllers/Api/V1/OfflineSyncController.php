<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Couriers\OfflineSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Livreur : envoi d'une action faite sans réseau (une à la fois, dans l'ordre). Toujours 200 avec
 * le résultat (appliquée ou refusée) : l'application distingue ainsi un refus d'une coupure réseau.
 */
class OfflineSyncController extends Controller
{
    public function __invoke(Request $request, OfflineSync $sync): JsonResponse
    {
        $action = $request->validate([
            'id' => ['required', 'string', 'max:64'],
            'kind' => ['required', Rule::in(OfflineSync::KINDS)],
            'order_id' => ['required', 'integer'],
            'occurred_at' => ['nullable', 'string', 'max:40'],
            'payload' => ['sometimes', 'array'],
        ]);

        return response()->json(['data' => $sync->apply($request->user(), $action)]);
    }
}
