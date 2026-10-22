<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SurchargeType;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PricingGridResource;
use App\Models\PricingGrid;
use App\Models\PricingRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PricingGridController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PricingGridResource::collection(
            PricingGrid::withCount(['rules', 'merchants'])->orderByDesc('is_default')->orderBy('name')->get()
        );
    }

    public function show(PricingGrid $pricingGrid): PricingGridResource
    {
        return PricingGridResource::make($pricingGrid->load(['rules', 'surcharges'])->loadCount('merchants'));
    }

    public function store(Request $request): PricingGridResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $grid = DB::transaction(function () use ($data) {
            $grid = PricingGrid::create($data);
            $this->ensureSingleDefault($grid);

            return $grid;
        });

        return PricingGridResource::make($grid->load(['rules', 'surcharges']));
    }

    public function update(Request $request, PricingGrid $pricingGrid): PricingGridResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'is_default' => ['sometimes', 'accepted'], // on désigne une nouvelle grille par défaut, on ne retire pas
        ]);

        DB::transaction(function () use ($pricingGrid, $data) {
            $pricingGrid->update($data);
            $this->ensureSingleDefault($pricingGrid);
        });

        return PricingGridResource::make($pricingGrid->load(['rules', 'surcharges']));
    }

    public function destroy(PricingGrid $pricingGrid): JsonResponse
    {
        if ($pricingGrid->is_default) {
            throw ValidationException::withMessages(['grid' => 'La grille par défaut ne peut pas être supprimée.']);
        }

        // Les marchands concernés repassent sur la grille par défaut (clé étrangère nullOnDelete)
        $pricingGrid->delete();

        return response()->json(null, 204);
    }

    /**
     * Remplace les tarifs de la grille (matrice zone -> zone).
     */
    public function syncRules(Request $request, PricingGrid $pricingGrid): PricingGridResource
    {
        $zone = Rule::exists('zones', 'id')->where('company_id', $pricingGrid->company_id);

        $data = $request->validate([
            'rules' => ['present', 'array', 'max:2000'],
            'rules.*.origin_zone_id' => ['required', 'integer', $zone],
            'rules.*.destination_zone_id' => ['required', 'integer', $zone],
            'rules.*.price' => ['required', 'integer', 'min:0', 'max:1000000'],
            'rules.*.is_symmetric' => ['sometimes', 'boolean'],
        ], [], ['rules.*.price' => 'prix']);

        $routes = collect($data['rules'])->map(fn ($r) => $r['origin_zone_id'].'-'.$r['destination_zone_id']);
        if ($routes->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['rules' => 'Un même trajet apparaît plusieurs fois.']);
        }

        DB::transaction(function () use ($pricingGrid, $data) {
            $pricingGrid->rules()->delete();

            foreach ($data['rules'] as $rule) {
                PricingRule::create([
                    'pricing_grid_id' => $pricingGrid->id,
                    'origin_zone_id' => $rule['origin_zone_id'],
                    'destination_zone_id' => $rule['destination_zone_id'],
                    'price' => $rule['price'],
                    'is_symmetric' => $rule['is_symmetric'] ?? true,
                ]);
            }

            $pricingGrid->touch();
        });

        return PricingGridResource::make($pricingGrid->load(['rules', 'surcharges']));
    }

    /**
     * Remplace les suppléments de la grille.
     */
    public function syncSurcharges(Request $request, PricingGrid $pricingGrid): PricingGridResource
    {
        $data = $request->validate([
            'surcharges' => ['present', 'array', 'max:50'],
            'surcharges.*.type' => ['required', Rule::enum(SurchargeType::class)],
            'surcharges.*.min_value' => ['nullable', 'numeric', 'min:0'],
            'surcharges.*.max_value' => ['nullable', 'numeric', 'gt:surcharges.*.min_value'],
            'surcharges.*.amount' => ['required_without:surcharges.*.percent', 'nullable', 'integer', 'min:0'],
            'surcharges.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        DB::transaction(function () use ($pricingGrid, $data) {
            $pricingGrid->surcharges()->delete();

            foreach ($data['surcharges'] as $surcharge) {
                $pricingGrid->surcharges()->create([...$surcharge, 'amount' => $surcharge['amount'] ?? 0]);
            }

            $pricingGrid->touch();
        });

        return PricingGridResource::make($pricingGrid->load(['rules', 'surcharges']));
    }

    private function ensureSingleDefault(PricingGrid $grid): void
    {
        if ($grid->is_default) {
            PricingGrid::whereKeyNot($grid->id)->where('is_default', true)->update(['is_default' => false]);
        }
    }
}
