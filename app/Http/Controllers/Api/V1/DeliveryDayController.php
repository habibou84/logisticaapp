<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\DeliveryDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Journée de livraison (mode « validation automatique avec règles ») : état pour le dispatch
 * et l'application marchand, fermeture et réouverture par le dispatch.
 */
class DeliveryDayController extends Controller
{
    public function __construct(private readonly DeliveryDay $day) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $this->day->status($user->company, withCount: $user->merchant_id === null && $user->can('orders.dispatch'))]);
    }

    public function close(Request $request): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);
        $data = $request->validate(['postpone' => ['sometimes', 'boolean']]);
        $company = $request->user()->company;

        if (! $this->day->rulesApply($company)) {
            throw new BusinessRuleException('Activez d\'abord la validation automatique avec règles dans les paramètres.');
        }

        $postponed = $this->day->close($company, $request->user(), (bool) ($data['postpone'] ?? false));

        return response()->json(['data' => [...$this->day->status($company->fresh(), withCount: true), 'postponed' => $postponed]]);
    }

    public function reopen(Request $request): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);
        $company = $request->user()->company;
        $this->day->reopen($company);

        return response()->json(['data' => $this->day->status($company->fresh(), withCount: true)]);
    }
}
