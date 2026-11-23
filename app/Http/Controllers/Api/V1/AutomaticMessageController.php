<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Messaging\AutomaticMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WhatsApp › Messages automatiques : tous les messages qui partent tout seuls, rangés par destinataire,
 * avec l'interrupteur qui décide de chacun.
 */
class AutomaticMessageController extends Controller
{
    public function __construct(private readonly AutomaticMessages $messages) {}

    public function index(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        abort_if($company === null, 422, 'Choisissez une entreprise.');

        return response()->json(['data' => [
            'audiences' => AutomaticMessages::AUDIENCES,
            'messages' => $this->messages->describe($company),
        ]]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $company = $request->user()->company;
        abort_if($company === null, 422, 'Choisissez une entreprise.');
        $data = $request->validate(['on' => ['required', 'boolean']]);

        $this->messages->set($company, $id, $data['on']);

        return response()->json(['data' => collect($this->messages->describe($company->fresh()))->firstWhere('id', $id)]);
    }
}
