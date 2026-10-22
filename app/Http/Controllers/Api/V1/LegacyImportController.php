<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Legacy\LegacyAccountsImporter;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reprise depuis l'ancienne application (Laravel 5.8) par un bouton : l'administrateur crée une clé
 * (Paramètres), la colle dans la page « Migration vers Jibiat » de l'ancienne application, qui envoie
 * ses comptes par paquets : e-commerçants, puis livreurs. Les comptes de l'équipe se recréent.
 */
class LegacyImportController extends Controller
{
    private const KEY_DAYS = 7;

    /** Lignes par table et par envoi (l'ancienne application envoie par paquets de 200) */
    private const MAX_ROWS = 500;

    /** Module à copier dans l'ancienne application */
    public const MODULE = 'legacy/laravel-5.8/JibiatMigrationController.php';

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->describe($request->user()->company)]);
    }

    /** Nouvelle clé (affichée une seule fois) ; l'ancienne ne fonctionne plus */
    public function createKey(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $key = 'jbm_'.Str::random(40);
        $company->forceFill([
            'legacy_import_key_hash' => hash('sha256', $key),
            'legacy_import_key_expires_at' => now()->addDays(self::KEY_DAYS),
        ])->save();

        return response()->json(['data' => [...$this->describe($company), 'key' => $key]], 201);
    }

    public function destroyKey(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $company->forceFill(['legacy_import_key_hash' => null, 'legacy_import_key_expires_at' => null])->save();

        return response()->json(['data' => $this->describe($company)]);
    }

    public function module(): BinaryFileResponse
    {
        return response()->download(base_path(self::MODULE), 'JibiatMigrationController.php', ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * Paquet envoyé par l'ancienne application. « step » : clients ou livreurs, avec les comptes
     * « users » qui leur sont liés.
     */
    public function receive(Request $request, LegacyAccountsImporter $importer): JsonResponse
    {
        $company = $this->companyForKey((string) $request->bearerToken());
        if ($company === null) {
            return response()->json(['message' => 'Clé de reprise invalide ou expirée : créez-en une dans Paramètres › Reprise de l\'ancienne application.'], 401);
        }

        // Sur la plateforme, la clé ne vaut que pour l'adresse de son entreprise
        $here = Tenancy::enabled() ? app(Tenancy::class)->company() : null;
        if ($here !== null && $here->id !== $company->id) {
            return response()->json(['message' => 'Cette clé appartient à une autre entreprise : vérifiez l\'adresse saisie.'], 403);
        }

        $data = $request->validate([
            'step' => ['required', 'in:clients,livreurs'],
            'dry_run' => ['sometimes', 'boolean'],
            'users' => ['present', 'array', 'max:'.self::MAX_ROWS * 3],
            'users.*' => ['array'],
            'clients' => ['sometimes', 'array', 'max:'.self::MAX_ROWS],
            'clients.*' => ['array'],
            'livreurs' => ['sometimes', 'array', 'max:'.self::MAX_ROWS],
            'livreurs.*' => ['array'],
        ]);

        $step = $data['step'];
        $report = $importer->importRows(
            $company,
            $this->rows($data['users']),
            $step === 'clients' ? $this->rows($data['clients'] ?? []) : [],
            $step === 'livreurs' ? $this->rows($data['livreurs'] ?? []) : [],
            (bool) ($data['dry_run'] ?? false),
        );

        if (! ($data['dry_run'] ?? false)) {
            $company->forceFill(['legacy_import_used_at' => now()])->save();
        }

        return response()->json(['data' => $report]);
    }

    private function companyForKey(string $key): ?Company
    {
        if (! str_starts_with($key, 'jbm_')) {
            return null;
        }

        return Company::query()
            ->where('legacy_import_key_hash', hash('sha256', $key))
            ->where('legacy_import_key_expires_at', '>', now())
            ->first();
    }

    /**
     * Valeurs telles que dans l'ancienne base : texte, ou null (comme la lecture d'un export SQL).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, string|null>>
     */
    private function rows(array $rows): array
    {
        return array_values(array_map(fn (array $row) => array_map(
            fn ($value) => $value === null || is_array($value) ? null : (is_bool($value) ? (string) (int) $value : (string) $value),
            $row,
        ), $rows));
    }

    /**
     * @return array{active: bool, expires_at: string|null, used_at: string|null}
     */
    private function describe(Company $company): array
    {
        $expires = $company->legacy_import_key_expires_at;

        return [
            'active' => $company->legacy_import_key_hash !== null && $expires?->isFuture(),
            'expires_at' => $expires?->toIso8601String(),
            'used_at' => $company->legacy_import_used_at?->toIso8601String(),
        ];
    }
}
