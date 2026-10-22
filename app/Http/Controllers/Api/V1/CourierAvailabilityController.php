<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Services\Couriers\CourierAvailability;
use App\Services\Couriers\PickupCustody;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Disponibilité des livreurs (dispatch) : qui a fini sa tournée, où, et quels colis
 * ramasser près de lui ; mission ramassage confiée d'un coup.
 */
class CourierAvailabilityController extends Controller
{
    public function index(Request $request, CourierAvailability $availability, PickupCustody $custody): JsonResponse
    {
        $company = $request->user()->company;

        return response()->json([
            'data' => $availability->board($company),
            'meta' => [
                'soon' => CourierAvailability::SOON,
                'radius_km' => CourierAvailability::RADIUS_KM,
                // Après la mission : garde, dépôt, ou au choix du dispatch
                'custody' => $custody->setting($company),
                'depot_closes_at' => $company->depot_closes_at,
                'depot_closed' => $custody->depotClosed($company),
            ],
        ]);
    }

    public function show(Courier $courier, CourierAvailability $availability): JsonResponse
    {
        return response()->json(['data' => $availability->forCourier($courier)]);
    }

    public function pickupMission(Request $request, Courier $courier, CourierAvailability $availability): JsonResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['integer', 'distinct'],
            'custody' => ['nullable', 'in:keep,depot'],
        ], [], ['order_ids' => 'colis', 'custody' => 'garde des colis']);

        return response()->json(['data' => $availability->assignPickupMission($request->user(), $courier, $data['order_ids'], $data['custody'] ?? null)]);
    }
}
