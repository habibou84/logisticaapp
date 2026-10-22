<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PlatformFeature;
use App\Http\Controllers\Controller;
use App\Services\Platform\CustomDomains;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Domaine personnalisé de l'entreprise (Paramètres) : choisir, vérifier, retirer.
 */
class CustomDomainController extends Controller
{
    public function __construct(private readonly CustomDomains $domains) {}

    public function show(Request $request): JsonResponse
    {
        $company = $request->user()->company;

        return response()->json(['data' => [
            ...$this->domains->describe($company),
            // Module de la formule : sinon l'écran présente l'option sans permettre de la configurer
            'included' => $company->hasFeature(PlatformFeature::CustomDomain),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['domain' => ['required', 'string', 'max:255']], [], ['domain' => 'adresse']);

        $company = $this->domains->set($request->user()->company, $data['domain']);

        return response()->json(['data' => $this->domains->check($company)]);
    }

    public function check(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->domains->check($request->user()->company)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->domains->describe($this->domains->remove($request->user()->company))]);
    }
}
