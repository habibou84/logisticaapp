<?php

namespace App\Services\Photos;

use App\Models\Company;

/**
 * Réglages des photos de documents (Réglages › Courses et livraison › Photos de documents) : où le scanner
 * de l'application livreur s'applique, rendu proposé, contrôle de la qualité, photo d'origine gardée,
 * résolution.
 */
class PhotoSettings
{
    /** Documents passés au scanner : accusé ou décharge, tickets et reçus, remise des retours, véhicule */
    public const SCAN = ['delivery_proof', 'receipts', 'returns', 'vehicle'];

    public const MODES = ['color', 'gray', 'bw'];

    /** Photo floue, sombre ou sans document : refusée, signalée, ou pas de contrôle */
    public const QUALITY_CHECK = ['block', 'warn', 'off'];

    /** Grand côté de la photo traitée : 2 000 pixels (scan A4 à 200 points par pouce) ou 3 000 */
    public const RESOLUTIONS = ['standard' => 2000, 'high' => 3000];

    public const DEFAULTS = [
        'scan' => ['delivery_proof' => true, 'receipts' => true, 'returns' => true, 'vehicle' => true],
        'mode' => 'color',
        'quality_check' => 'block',
        'keep_original' => true,
        'resolution' => 'standard',
    ];

    /** @return array<string, mixed> */
    public function for(?Company $company): array
    {
        $saved = self::clean((array) ($company?->photo_settings ?? []));

        return [...self::DEFAULTS, ...$saved, 'scan' => [...self::DEFAULTS['scan'], ...($saved['scan'] ?? [])]];
    }

    public function keepsOriginal(?Company $company): bool
    {
        return (bool) $this->for($company)['keep_original'];
    }

    /** @return array<string, mixed> */
    public static function rules(string $prefix = 'photo_settings'): array
    {
        return [
            $prefix => ['sometimes', 'nullable', 'array'],
            "{$prefix}.scan" => ['nullable', 'array'],
            ...collect(self::SCAN)->mapWithKeys(fn ($k) => ["{$prefix}.scan.{$k}" => ['nullable', 'boolean']])->all(),
            "{$prefix}.mode" => ['nullable', 'in:'.implode(',', self::MODES)],
            "{$prefix}.quality_check" => ['nullable', 'in:'.implode(',', self::QUALITY_CHECK)],
            "{$prefix}.keep_original" => ['nullable', 'boolean'],
            "{$prefix}.resolution" => ['nullable', 'in:'.implode(',', array_keys(self::RESOLUTIONS))],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function clean(array $input): array
    {
        $out = [];
        foreach (self::SCAN as $key) {
            if (isset($input['scan'][$key]) && $input['scan'][$key] !== '') {
                $out['scan'][$key] = (bool) $input['scan'][$key];
            }
        }
        foreach (['mode' => self::MODES, 'quality_check' => self::QUALITY_CHECK, 'resolution' => array_keys(self::RESOLUTIONS)] as $key => $allowed) {
            if (in_array($input[$key] ?? null, $allowed, true)) {
                $out[$key] = $input[$key];
            }
        }
        if (isset($input['keep_original']) && $input['keep_original'] !== '') {
            $out['keep_original'] = (bool) $input['keep_original'];
        }

        return $out;
    }
}
