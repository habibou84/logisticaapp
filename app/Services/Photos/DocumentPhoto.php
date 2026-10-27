<?php

namespace App\Services\Photos;

use App\Models\Company;
use Illuminate\Http\UploadedFile;

/**
 * Photo envoyée par l'application livreur : version traitée par le scanner (recadrée, redressée, nettoyée),
 * photo d'origine gardée à côté quand le réglage le demande (valeur de preuve), et informations de la prise.
 */
class DocumentPhoto
{
    /** Avertissements du contrôle de qualité de l'application */
    public const WARNINGS = ['blur', 'dark', 'glare', 'small', 'no_document'];

    public function __construct(private readonly PhotoSettings $settings) {}

    /** Règles des champs d'une photo : fichier, photo d'origine, informations de la prise */
    public static function rules(string $field = 'file', bool $required = true): array
    {
        return [
            $field => [$required ? 'required' : 'nullable', 'image', 'max:15360'],
            "{$field}_original" => ['nullable', 'image', 'max:15360'],
            "{$field}_meta" => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Enregistre la photo (et son original) dans le dossier ; renvoie les chemins et les informations.
     *
     * @return array{path: string, original_path: ?string, meta: ?array, mime_type: ?string}
     */
    public function store(Company $company, UploadedFile $file, ?UploadedFile $original, ?string $meta, string $dir): array
    {
        $keep = $original !== null && $this->settings->keepsOriginal($company);

        return [
            'path' => $file->store($dir, 'local'),
            'original_path' => $keep ? $original->store("{$dir}/originaux", 'local') : null,
            'meta' => self::meta($meta),
            'mime_type' => $file->getMimeType(),
        ];
    }

    /**
     * Informations de la prise envoyées par l'application (JSON), réduites aux clés connues.
     *
     * @return array<string, mixed>|null
     */
    public static function meta(?string $json): ?array
    {
        $input = json_decode((string) $json, true);
        if (! is_array($input)) {
            return null;
        }
        $out = [];
        foreach (['scanned', 'cropped', 'overridden'] as $key) {
            if (isset($input[$key])) {
                $out[$key] = (bool) $input[$key];
            }
        }
        if (in_array($input['mode'] ?? null, PhotoSettings::MODES, true)) {
            $out['mode'] = $input['mode'];
        }
        foreach (['width', 'height', 'original_width', 'original_height'] as $key) {
            if (isset($input[$key]) && is_numeric($input[$key])) {
                $out[$key] = max(0, min(20000, (int) $input[$key]));
            }
        }
        foreach (['blur', 'brightness', 'glare'] as $key) {
            if (isset($input['quality'][$key]) && is_numeric($input['quality'][$key])) {
                $out['quality'][$key] = round((float) $input['quality'][$key], 3);
            }
        }
        if (isset($input['quality']['warnings']) && is_array($input['quality']['warnings'])) {
            $out['quality']['warnings'] = array_values(array_intersect(self::WARNINGS, $input['quality']['warnings']));
        }
        if (isset($input['taken_at']) && is_string($input['taken_at']) && strtotime($input['taken_at']) !== false) {
            $out['taken_at'] = date(DATE_ATOM, strtotime($input['taken_at']));
        }
        if (isset($input['lat'], $input['lng']) && is_numeric($input['lat']) && is_numeric($input['lng'])
            && abs((float) $input['lat']) <= 90 && abs((float) $input['lng']) <= 180) {
            $out['lat'] = round((float) $input['lat'], 6);
            $out['lng'] = round((float) $input['lng'], 6);
        }

        return $out ?: null;
    }
}
