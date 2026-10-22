<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Reports\OrderSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    /**
     * Point détaillé sur une période : volumes par état et montants.
     * Un marchand ne voit que ses courses ; le personnel peut filtrer par marchand.
     */
    public function summary(Request $request, OrderSummary $summary): JsonResponse
    {
        Gate::authorize('viewAny', Order::class);

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'merchant_id' => ['nullable', 'integer'],
            'date_basis' => ['nullable', Rule::in(['created', 'delivery'])],
        ]);

        $orders = Order::query()
            ->visibleTo($request->user())
            ->when(isset($data['merchant_id']) && $request->user()->merchant_id === null, fn ($q) => $q->where('merchant_id', $data['merchant_id']));

        return response()->json(['data' => $summary->build(
            $orders,
            $request->date('from') ?? today()->startOfMonth(),
            $request->date('to') ?? today(),
            $data['date_basis'] ?? 'created',
        )]);
    }
}
