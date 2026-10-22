<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PlatformFeature;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Console du super administrateur : formules d'abonnement de la plateforme.
 */
class ConsolePlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = Plan::query()->withCount('companies')->orderBy('position')->orderBy('id')->get();

        return response()->json([
            'data' => $plans->map(fn (Plan $p) => $this->planData($p))->values(),
            'meta' => [
                'features' => array_map(fn (PlatformFeature $f) => [
                    'key' => $f->value, 'label' => $f->label(), 'description' => $f->description(),
                ], PlatformFeature::cases()),
                'trial_days' => (int) config('platform.trial_days'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $plan = Plan::create($this->validated($request));

        return response()->json(['data' => $this->planData($plan->loadCount('companies'))], 201);
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        $plan->update($this->validated($request, $plan));

        return response()->json(['data' => $this->planData($plan->loadCount('companies'))]);
    }

    /**
     * Crée les formules proposées (Essentiel, Pro, Entreprise) quand il n'y en a encore aucune.
     */
    public function suggested(): JsonResponse
    {
        abort_if(Plan::query()->exists(), 422, 'Des formules existent déjà.');

        foreach (Plan::suggestions() as $data) {
            Plan::create($data);
        }

        return $this->index()->setStatusCode(201);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Plan $plan = null): array
    {
        $required = $plan ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:60', Rule::unique('plans', 'name')->ignore($plan)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'price_monthly' => [$required, 'integer', 'min:0', 'max:100000000'],
            'included_orders' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'],
            'overage_per_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'max_couriers' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['string', Rule::enum(PlatformFeature::class)],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ], [], [
            'name' => 'nom',
            'price_monthly' => 'prix mensuel',
            'included_orders' => 'courses incluses',
            'overage_per_order' => 'prix par course en plus',
            'max_couriers' => 'livreurs',
            'features' => 'modules',
        ]);

        if (array_key_exists('features', $data)) {
            $data['features'] = array_values(array_unique($data['features']));
        } elseif ($plan === null) {
            $data['features'] = [];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function planData(Plan $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'price_monthly' => $p->price_monthly,
            'included_orders' => $p->included_orders,
            'overage_per_order' => $p->overage_per_order,
            'max_couriers' => $p->max_couriers,
            'features' => $p->features ?? [],
            'is_active' => $p->is_active,
            'position' => $p->position,
            'companies_count' => $p->companies_count,
        ];
    }
}
