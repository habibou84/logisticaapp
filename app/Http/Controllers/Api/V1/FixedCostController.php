<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\FixedCost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Frais fixes de l'entreprise (Statistiques › Rentabilité) : loyer, salaires de l'équipe, téléphone… comptés dans la
 * marge nette. Lecture avec la caisse, saisie avec « Encaisser et reverser ».
 */
class FixedCostController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permission::FinanceView->value) && $request->user()->merchant_id === null, 403);

        return response()->json([
            'data' => FixedCost::query()->orderByDesc('starts_on')->orderBy('label')->get()->map(fn (FixedCost $c) => $this->present($c))->values(),
            'categories' => FixedCost::CATEGORIES,
            'frequencies' => FixedCost::FREQUENCIES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $cost = FixedCost::create([...$data, 'company_id' => $request->user()->company_id, 'created_by' => $request->user()->id]);

        return response()->json(['data' => $this->present($cost)], 201);
    }

    public function update(Request $request, FixedCost $fixedCost): JsonResponse
    {
        $fixedCost->update($this->validated($request));

        return response()->json(['data' => $this->present($fixedCost->fresh())]);
    }

    public function destroy(Request $request, FixedCost $fixedCost): JsonResponse
    {
        $this->authorizeManage($request);
        $fixedCost->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $this->authorizeManage($request);

        return $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(array_keys(FixedCost::CATEGORIES))],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'frequency' => ['required', Rule::in(array_keys(FixedCost::FREQUENCIES))],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['label' => 'libellé', 'amount' => 'montant', 'starts_on' => 'début', 'ends_on' => 'fin']);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can(Permission::FinanceManage->value) && $request->user()->merchant_id === null, 403);
    }

    /** @return array<string, mixed> */
    private function present(FixedCost $c): array
    {
        return [
            ...$c->only(['id', 'label', 'category', 'amount', 'frequency', 'notes']),
            'category_label' => FixedCost::CATEGORIES[$c->category] ?? $c->category,
            'starts_on' => $c->starts_on?->toDateString(),
            'ends_on' => $c->ends_on?->toDateString(),
        ];
    }
}
