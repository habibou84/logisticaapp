<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CourierResource;
use App\Models\Courier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photo du livreur et photo de sa pièce d'identité (disque privé, lues par l'équipe).
 */
class CourierFileController extends Controller
{
    /** Fichier de l'URL → colonne du livreur */
    public const KINDS = ['photo' => 'photo_path', 'id-document' => 'id_document_path'];

    public function store(Request $request, Courier $courier, string $kind): CourierResource
    {
        // Pas de SVG : un SVG peut contenir du script
        $request->validate([
            'file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
        ], [], ['file' => $kind === 'photo' ? 'photo' : 'pièce d\'identité']);

        $column = self::KINDS[$kind];
        $previous = $courier->{$column};
        $path = $request->file('file')->store("couriers/{$courier->id}", 'local');
        $courier->forceFill([$column => $path])->save();

        if ($previous && $previous !== $path) {
            Storage::disk('local')->delete($previous);
        }

        return CourierResource::make($courier->load(['user', 'zones', 'payPlan'])->loadCount('activeAssignments'));
    }

    public function destroy(Courier $courier, string $kind): JsonResponse
    {
        $column = self::KINDS[$kind];
        if ($courier->{$column}) {
            Storage::disk('local')->delete($courier->{$column});
            $courier->forceFill([$column => null])->save();
        }

        return response()->json(null, 204);
    }

    public function show(Courier $courier, string $kind): StreamedResponse
    {
        $path = $courier->{self::KINDS[$kind]};
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['X-Content-Type-Options' => 'nosniff']);
    }
}
