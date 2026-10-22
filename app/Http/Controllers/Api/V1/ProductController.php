<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProductResource;
use App\Http\Resources\V1\StockMovementResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    private const RELATIONS = ['merchant', 'levels.location.hub'];

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Product::class);

        $request->validate([
            'merchant_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'low' => ['nullable', 'boolean'],
            'include_inactive' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $available = '(SELECT COALESCE(SUM(on_hand - reserved), 0) FROM stock_levels WHERE stock_levels.product_id = products.id)';

        $products = Product::query()
            ->visibleTo($request->user())
            ->with(self::RELATIONS)
            ->when($request->filled('merchant_id'), fn ($q) => $q->where('merchant_id', $request->integer('merchant_id')))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.mb_strtolower(trim($request->string('search'))).'%';
                $q->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])->orWhereRaw('LOWER(sku) LIKE ?', [$term]));
            })
            ->when($request->boolean('low'), fn ($q) => $q->whereNotNull('low_stock_threshold')->whereRaw("{$available} <= low_stock_threshold"))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return ProductResource::collection($products);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        Gate::authorize('view', $product);

        $movements = $product->movements()->with(['location.hub', 'order', 'user'])->latest('id')->limit(30)->get();

        return response()->json([
            'data' => ProductResource::make($product->load(self::RELATIONS))->resolve($request),
            'movements' => StockMovementResource::collection($movements)->resolve($request),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            ...$this->rules($request, $user->merchant_id ?? $request->integer('merchant_id')),
            'merchant_id' => [
                Rule::requiredIf($user->merchant_id === null),
                Rule::prohibitedIf($user->merchant_id !== null),
                'integer',
                Rule::exists('merchants', 'id')->where('company_id', $user->company_id)->whereNull('deleted_at'),
            ],
        ]);
        $data['merchant_id'] = $user->merchant_id ?? $data['merchant_id'];

        Gate::authorize('manageFor', [Product::class, $data['merchant_id']]);

        $product = Product::create($data);

        return ProductResource::make($product->load(self::RELATIONS))->response()->setStatusCode(201);
    }

    public function update(Request $request, Product $product): ProductResource
    {
        Gate::authorize('update', $product);

        $product->update($request->validate($this->rules($request, $product->merchant_id, $product)));

        return ProductResource::make($product->load(self::RELATIONS));
    }

    public function destroy(Product $product): JsonResponse
    {
        Gate::authorize('delete', $product);

        if ($product->levels()->where(fn ($q) => $q->where('on_hand', '!=', 0)->orWhere('reserved', '>', 0))->exists()) {
            throw new BusinessRuleException('Ce produit a encore du stock : retirez-le ou désactivez le produit.');
        }

        $product->delete();

        return response()->json(null, 204);
    }

    private function rules(Request $request, int $merchantId, ?Product $product = null): array
    {
        $required = $product ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:60',
                Rule::unique('products')->where('merchant_id', $merchantId)->ignore($product)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'price' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'weight_kg' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:500'],
            'low_stock_threshold' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
            // Proposé sur la page de commande du marchand
            'shop_visible' => ['sometimes', 'boolean'],
        ];
    }
}
