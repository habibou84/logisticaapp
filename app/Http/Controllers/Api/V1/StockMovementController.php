<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProductResource;
use App\Http\Resources\V1\StockMovementResource;
use App\Models\Hub;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Stock\StockKeeper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StockMovementController extends Controller
{
    public function __construct(private readonly StockKeeper $stock) {}

    /**
     * Journal des mouvements (le marchand ne voit que ses produits).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Product::class);

        $request->validate([
            'merchant_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'hub_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', 'max:20'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $user = $request->user();
        $merchantId = $user->merchant_id ?? ($request->filled('merchant_id') ? $request->integer('merchant_id') : null);

        $movements = StockMovement::query()
            ->with(['product.merchant', 'location.hub', 'order', 'user'])
            ->when($merchantId, fn ($q) => $q->whereHas('product', fn ($p) => $p->withTrashed()->where('merchant_id', $merchantId)))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->integer('product_id')))
            ->when($request->filled('hub_id'), fn ($q) => $q->whereHas('location', fn ($l) => $l->where('hub_id', $request->integer('hub_id'))))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->latest('id')
            ->paginate($request->integer('per_page', 50));

        return StockMovementResource::collection($movements);
    }

    /**
     * Entrée, retrait ou inventaire d'un produit à un emplacement.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'hub_id' => ['nullable', 'integer', Rule::exists('hubs', 'id')->where('company_id', $user->company_id)],
            'action' => ['required', Rule::in(['receipt', 'withdrawal', 'count'])],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $product = Product::visibleTo($user)->findOrFail($data['product_id']);
        $hubId = $data['hub_id'] ?? null;

        Gate::authorize('moveStock', [$product, $hubId]);

        $location = $this->stock->location($product->merchant, $hubId ? Hub::findOrFail($hubId) : null);
        $note = $data['note'] ?? null;

        $movement = match ($data['action']) {
            'receipt' => $this->stock->receive($user, $product, $location, $data['quantity'], $note),
            'withdrawal' => $this->stock->withdraw($user, $product, $location, $data['quantity'], $note),
            'count' => $this->stock->count($user, $product, $location, $data['quantity'], $note),
        };

        return response()->json([
            'data' => $movement ? StockMovementResource::make($movement->load(['location.hub', 'user']))->resolve($request) : null,
            'product' => ProductResource::make($product->fresh()->load(['merchant', 'levels.location.hub']))->resolve($request),
        ], $movement ? 201 : 200);
    }
}
