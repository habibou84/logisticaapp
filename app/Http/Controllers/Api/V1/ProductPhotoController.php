<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photo d'un produit (page de commande du marchand).
 */
class ProductPhotoController extends Controller
{
    public function store(Request $request, Product $product): ProductResource
    {
        Gate::authorize('update', $product);

        // Pas de SVG : un SVG peut contenir du script
        $request->validate([
            'photo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:4096', 'dimensions:min_width=100,min_height=100,max_width=6000,max_height=6000'],
        ], [], ['photo' => 'photo']);

        $previous = $product->photo_path;
        $path = $request->file('photo')->store("products/merchant-{$product->merchant_id}", 'local');
        $product->forceFill(['photo_path' => $path])->save();

        if ($previous && $previous !== $path) {
            Storage::disk('local')->delete($previous);
        }

        return ProductResource::make($product->load('levels.location.hub'));
    }

    public function destroy(Product $product): JsonResponse
    {
        Gate::authorize('update', $product);

        if ($product->photo_path) {
            Storage::disk('local')->delete($product->photo_path);
            $product->forceFill(['photo_path' => null])->save();
        }

        return response()->json(null, 204);
    }

    /**
     * Fichier de la photo (public : affiché sur la page de commande).
     */
    public function show(Product $product): StreamedResponse
    {
        abort_if($product->photo_path === null || ! Storage::disk('local')->exists($product->photo_path), 404);

        return Storage::disk('local')->response($product->photo_path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
