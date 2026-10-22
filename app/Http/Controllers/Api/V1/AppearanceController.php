<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\Appearance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Paramètres › Apparence : couleurs des espaces, noms et icônes des applications installables.
 */
class AppearanceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => Appearance::for($this->company($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $data = $request->validate([
            'same' => ['required', 'boolean'],
            'spaces' => ['required', 'array'],
            'spaces.*.color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'spaces.*.app_name' => ['nullable', 'string', 'max:30'],
        ], [], ['spaces.*.color' => 'couleur', 'spaces.*.app_name' => 'nom de l\'application']);

        $saved = $company->appearance ?? [];
        $saved['same'] = $data['same'];
        foreach (Appearance::SPACES as $space) {
            if (! isset($data['spaces'][$space])) {
                continue;
            }
            $saved['spaces'][$space]['color'] = strtolower($data['spaces'][$space]['color']);
            if (in_array($space, Appearance::APPS, true)) {
                $saved['spaces'][$space]['app_name'] = trim((string) ($data['spaces'][$space]['app_name'] ?? '')) ?: null;
            }
        }
        $company->forceFill(['appearance' => $saved])->save();

        return response()->json(['data' => Appearance::for($company)]);
    }

    /** Icône carrée de l'application d'un espace (à la place du logo sur la couleur de l'espace) */
    public function uploadIcon(Request $request, string $space): JsonResponse
    {
        abort_unless(in_array($space, Appearance::APPS, true), 404);
        $company = $this->company($request);

        // Pas de SVG : un SVG peut contenir du script
        $request->validate([
            'icon' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:min_width=192,min_height=192,max_width=4000,max_height=4000'],
        ], [], ['icon' => 'icône']);

        $saved = $company->appearance ?? [];
        $previous = $saved['spaces'][$space]['icon_path'] ?? null;
        $saved['spaces'][$space]['icon_path'] = $request->file('icon')->store("branding/company-{$company->id}", 'local');
        $company->forceFill(['appearance' => $saved])->save();
        if ($previous) {
            Storage::disk('local')->delete($previous);
        }

        return response()->json(['data' => Appearance::for($company)]);
    }

    public function destroyIcon(Request $request, string $space): JsonResponse
    {
        abort_unless(in_array($space, Appearance::APPS, true), 404);
        $company = $this->company($request);

        $saved = $company->appearance ?? [];
        if ($path = $saved['spaces'][$space]['icon_path'] ?? null) {
            Storage::disk('local')->delete($path);
            unset($saved['spaces'][$space]['icon_path']);
            $company->forceFill(['appearance' => $saved])->save();
        }

        return response()->json(['data' => Appearance::for($company)]);
    }

    private function company(Request $request): Company
    {
        $company = $request->user()->company;
        abort_unless($company && $request->user()->can('update', $company), 403);

        return $company;
    }
}
