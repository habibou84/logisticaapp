<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ExpenseType;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrderExpenseResource;
use App\Models\Order;
use App\Models\OrderExpense;
use App\Services\Finance\OrderExpenses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Frais d'une course : saisis par le livreur (toujours avancés par lui et facturés au
 * marchand) ou par le personnel (qui choisit qui a payé et qui supporte le coût).
 */
class OrderExpenseController extends Controller
{
    public function __construct(private readonly OrderExpenses $expenses) {}

    public function store(Request $request, Order $order): JsonResponse
    {
        Gate::authorize('addExpense', $order);
        $user = $request->user();

        $data = $request->validate([
            'type' => ['required', Rule::enum(ExpenseType::class)],
            'label' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1', 'max:10000000'],
            'paid_by' => ['sometimes', Rule::in(['courier', 'company'])],
            'courier_id' => ['nullable', 'integer', Rule::exists('couriers', 'id')->where('company_id', $order->company_id)],
            'billed_to' => ['sometimes', Rule::in(['merchant', 'company'])],
        ], [], ['amount' => 'montant', 'label' => 'précision', 'type' => 'type de frais']);

        if ($user->isCourier()) {
            // Le livreur déclare ce qu'il a payé (de sa poche ou avec une avance de la caisse)
            $data = [...$data, 'paid_by' => 'courier', 'courier_id' => $user->courier->id, 'billed_to' => 'merchant'];
        } elseif (($data['paid_by'] ?? 'courier') === 'courier') {
            $data['courier_id'] ??= OrderExpenses::defaultCourier($order)?->id;
        }

        $expense = $this->expenses->add($user, $order, $data);

        return response()->json(['data' => new OrderExpenseResource($expense->load('courier.user'))], 201);
    }

    public function cancel(Request $request, Order $order, OrderExpense $expense): OrderExpenseResource
    {
        Gate::authorize('cancelExpense', $order);
        abort_unless($expense->order_id === $order->id, 404);

        return new OrderExpenseResource($this->expenses->cancel($request->user(), $expense)->load('courier.user'));
    }
}
