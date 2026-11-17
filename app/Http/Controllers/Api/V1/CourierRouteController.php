<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Services\Couriers\CourierRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tournée du livreur (son application) : ses livraisons dans l'ordre, organisées depuis sa position ou à la main.
 */
class CourierRouteController extends Controller
{
    public function __construct(private readonly CourierRoute $route) {}

    public function show(Request $request): JsonResponse
    {
        [$lat, $lng] = $this->position($request);

        return response()->json(['data' => $this->route->show($this->courier($request), $lat, $lng)]);
    }

    /** Meilleur ordre calculé depuis la position du livreur */
    public function optimize(Request $request): JsonResponse
    {
        [$lat, $lng] = $this->position($request);

        return response()->json(['data' => $this->route->optimize($this->courier($request), $lat, $lng)]);
    }

    /** Ordre changé à la main */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'assignment_ids' => ['required', 'array', 'min:1', 'max:500'],
            'assignment_ids.*' => ['integer'],
        ]);
        [$lat, $lng] = $this->position($request);

        return response()->json(['data' => $this->route->reorder($this->courier($request), $data['assignment_ids'], $lat, $lng)]);
    }

    /**
     * @return array{0: float|null, 1: float|null}
     */
    private function position(Request $request): array
    {
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);

        return isset($data['lat'], $data['lng']) ? [(float) $data['lat'], (float) $data['lng']] : [null, null];
    }

    private function courier(Request $request): Courier
    {
        $courier = $request->user()->courier;
        abort_if($courier === null, 403, 'Profil livreur introuvable.');

        return $courier;
    }
}
