<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Order;
use App\Services\Orders\PickedLateDecisions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Courses en retard › « À décider » : retour ou report d'un ou plusieurs colis. Chaque colis est
 * traité seul ; ceux qui ne peuvent pas l'être sont renvoyés avec la raison.
 */
class PickedLateController extends Controller
{
    public function __construct(private readonly PickedLateDecisions $decisions) {}

    public function decide(Request $request): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);
        $companyId = $request->user()->company_id;
        $data = $request->validate([
            'decision' => ['required', Rule::in(['return', 'reschedule'])],
            'order_ids' => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['integer'],
            'courier_id' => ['nullable', 'integer', Rule::exists('couriers', 'id')->where('company_id', $companyId)],
            // Retour confié au livreur qui a déjà chaque colis
            'holder' => ['sometimes', 'boolean'],
            'date' => ['required_if:decision,reschedule', 'nullable', 'date_format:Y-m-d'],
            'keep_courier' => ['sometimes', 'boolean'],
        ], [], ['date' => 'nouvelle date', 'courier_id' => 'livreur', 'order_ids' => 'colis']);

        $courier = isset($data['courier_id']) ? Courier::find($data['courier_id']) : null;
        $done = 0;
        $errors = [];
        foreach (Order::query()->whereIn('id', $data['order_ids'])->orderBy('id')->get() as $order) {
            try {
                if ($data['decision'] === 'return') {
                    $who = ($data['holder'] ?? false) && $order->held_by_courier_id ? Courier::find($order->held_by_courier_id) : $courier;
                    $this->decisions->returnToMerchant($request->user(), $order, $who);
                } else {
                    $this->decisions->reschedule($request->user(), $order, $data['date'], (bool) ($data['keep_courier'] ?? true));
                }
                $done++;
            } catch (BusinessRuleException $e) {
                $errors[] = ['id' => $order->id, 'tracking_code' => $order->tracking_code, 'message' => $e->getMessage()];
            }
        }

        return response()->json(['data' => ['done' => $done, 'errors' => $errors]], $done === 0 && $errors ? 422 : 200);
    }
}
