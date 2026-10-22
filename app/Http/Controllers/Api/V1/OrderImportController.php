<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\Orders\OrderImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Import de courses depuis un fichier CSV ou Excel : aperçu contrôlé (dry_run), puis création.
 */
class OrderImportController extends Controller
{
    public function __construct(private readonly OrderImporter $importer) {}

    public function template(): Response
    {
        return response(OrderImporter::template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="modele-import-courses.csv"',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Order::class);
        $user = $request->user();

        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt,xlsx'],
            'merchant_id' => [
                Rule::requiredIf($user->merchant_id === null),
                Rule::prohibitedIf($user->merchant_id !== null),
                'integer',
                Rule::exists('merchants', 'id')->where('company_id', $user->company_id)->whereNull('deleted_at'),
            ],
            'dry_run' => ['sometimes', 'boolean'],
            'skip_invalid' => ['sometimes', 'boolean'],
        ], [], ['file' => 'fichier']);

        $merchant = Merchant::findOrFail($user->merchant_id ?? $data['merchant_id']);
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());

        if ($request->boolean('dry_run')) {
            return response()->json(['data' => $this->importer->preview($merchant, $file->getRealPath(), $extension)]);
        }

        $result = $this->importer->import($user, $merchant, $file->getRealPath(), $extension, $request->boolean('skip_invalid'));

        return response()->json(['data' => $result], $result['created'] ? 201 : 200);
    }
}
