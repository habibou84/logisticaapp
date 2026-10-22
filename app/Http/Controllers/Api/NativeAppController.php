<?php

namespace App\Http\Controllers\Api;

use App\Enums\CompanyStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\Branding;
use Illuminate\Http\JsonResponse;

/**
 * Applications Android et iOS : au premier lancement, l'utilisateur saisit le code de son
 * entreprise de livraison (son adresse sur la plateforme) ; l'application parle ensuite
 * directement au site de cette entreprise.
 */
class NativeAppController extends Controller
{
    public function company(string $code): JsonResponse
    {
        $company = Company::query()
            ->where('slug', mb_strtolower(trim($code)))
            ->where('status', CompanyStatus::Active->value)
            ->first();

        if ($company === null) {
            return response()->json(['message' => 'Code introuvable. Vérifiez-le auprès de votre entreprise de livraison.'], 404);
        }

        $logo = Branding::logoUrl($company);

        return response()->json(['data' => [
            'code' => $company->slug,
            'name' => $company->name,
            'url' => $company->url(),
            'logo_url' => $logo ? $company->url($logo) : null,
        ]]);
    }
}
