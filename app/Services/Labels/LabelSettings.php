<?php

namespace App\Services\Labels;

use App\Models\Company;
use App\Models\Merchant;

/**
 * Réglages des étiquettes (Réglages › Courses et livraison, et fiche du client) : les clients impriment-ils
 * eux-mêmes (oui, non, obligatoire avant le ramassage), champs imprimés, format proposé.
 */
class LabelSettings
{
    public const CLIENT_PRINT = ['yes', 'no', 'required'];

    public const FORMATS = ['thermal', 'a6', 'a4'];

    public const FIELDS = ['cod', 'recipient_phone', 'landmark', 'description', 'logo'];

    public const DEFAULTS = [
        'client_print' => 'yes',
        'default_format' => 'thermal',
        // Imprimés sur l'étiquette : montant à encaisser, téléphone du destinataire, repère, description, logo
        'fields' => ['cod' => true, 'recipient_phone' => true, 'landmark' => true, 'description' => true, 'logo' => true],
    ];

    /**
     * Réglage de l'entreprise, remplacé clé par clé par celui du client.
     *
     * @return array<string, mixed>
     */
    public function for(?Company $company, ?Merchant $merchant = null): array
    {
        $settings = self::merge(self::DEFAULTS, self::clean((array) ($company?->label_settings ?? [])));
        if ($merchant !== null) {
            $settings = self::merge($settings, self::clean((array) ($merchant->label_settings ?? [])));
        }

        return $settings;
    }

    /** @return array<string, mixed> */
    public static function rules(string $prefix = 'label_settings'): array
    {
        return [
            $prefix => ['sometimes', 'nullable', 'array'],
            "{$prefix}.client_print" => ['nullable', 'in:'.implode(',', self::CLIENT_PRINT)],
            "{$prefix}.default_format" => ['nullable', 'in:'.implode(',', self::FORMATS)],
            "{$prefix}.fields" => ['nullable', 'array'],
            ...collect(self::FIELDS)->mapWithKeys(fn ($f) => ["{$prefix}.fields.{$f}" => ['nullable', 'boolean']])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function clean(array $input): array
    {
        $out = [];
        if (in_array($input['client_print'] ?? null, self::CLIENT_PRINT, true)) {
            $out['client_print'] = $input['client_print'];
        }
        if (in_array($input['default_format'] ?? null, self::FORMATS, true)) {
            $out['default_format'] = $input['default_format'];
        }
        foreach (self::FIELDS as $field) {
            if (isset($input['fields'][$field]) && $input['fields'][$field] !== '') {
                $out['fields'][$field] = (bool) $input['fields'][$field];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function merge(array $base, array $over): array
    {
        return [...$base, ...$over, 'fields' => [...$base['fields'], ...($over['fields'] ?? [])]];
    }
}
