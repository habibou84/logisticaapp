<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Support\Appearance;
use App\Support\Branding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Identité visuelle de l'entreprise de livraison : lecture publique (pages de
 * connexion) et logo envoyé depuis les paramètres.
 */
class BrandingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => Branding::data(Branding::company())]);
    }

    public function logo(): StreamedResponse
    {
        $path = Branding::company()?->logo_path;
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Icône de l'application : générée pour l'entreprise, sinon l'icône standard */
    public function icon(string $app, string $variant): BinaryFileResponse
    {
        $company = Branding::company();
        $file = $company ? Appearance::renderIcon($company, $app, $variant) : null;

        return response()->file($file ?? public_path("app-icons/{$app}-{$variant}.png"), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $company = $this->editableCompany($request);

        // Pas de SVG : un SVG peut contenir du script
        $request->validate([
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000'],
        ], [], ['logo' => 'logo']);

        $previous = $company->logo_path;
        $path = $request->file('logo')->store("branding/company-{$company->id}", 'local');
        $company->forceFill(['logo_path' => $path])->save();

        if ($previous && $previous !== $path) {
            Storage::disk('local')->delete($previous);
        }

        return response()->json(['data' => ['logo_url' => Branding::logoUrl($company)]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $company = $this->editableCompany($request);

        if ($company->logo_path) {
            Storage::disk('local')->delete($company->logo_path);
            $company->forceFill(['logo_path' => null])->save();
        }

        return response()->json(['data' => ['logo_url' => null]]);
    }

    private function editableCompany(Request $request): Company
    {
        $company = $request->user()->company;
        abort_unless($company && $request->user()->can('update', $company), 403);

        return $company;
    }
}
