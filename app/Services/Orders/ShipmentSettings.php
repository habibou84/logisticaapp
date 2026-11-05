<?php

namespace App\Services\Orders;

use App\Models\Company;

/**
 * Réglages de l'envoi des courses de distribution et de MAD, au choix de l'administrateur (Réglages › Courses) :
 * - pli_kinds : ce que peut être un pli (facture, courrier, carte d'invitation…), proposé au client ;
 * - forfait_usage : le client voit le décompte de son forfait du mois (courses utilisées sur les courses incluses).
 */
class ShipmentSettings
{
    public const DEFAULT_PLI_KINDS = ['Facture', 'Courrier', 'Carte d\'invitation', 'Document administratif', 'Contrat', 'Relevé', 'Autre'];

    public const DEFAULTS = [
        'pli_kinds' => self::DEFAULT_PLI_KINDS,
        'forfait_usage' => false,
    ];

    /** Longueur d'un type de pli (colonne orders.pli_kind) */
    public const KIND_MAX = 60;

    /**
     * @return array{pli_kinds: list<string>, forfait_usage: bool}
     */
    public function for(?Company $company): array
    {
        $saved = $company?->shipment_settings ?? [];

        return [...self::DEFAULTS, ...self::clean(is_array($saved) ? $saved : [])];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'shipment_settings' => ['sometimes', 'nullable', 'array'],
            'shipment_settings.pli_kinds' => ['nullable', 'array', 'max:30'],
            'shipment_settings.pli_kinds.*' => ['nullable', 'string', 'max:'.self::KIND_MAX],
            'shipment_settings.forfait_usage' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Valeurs enregistrées : clés connues ; types de pli sans doublon ni ligne vide (liste vide : celle par défaut).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function clean(array $input): array
    {
        $out = [];
        if (isset($input['pli_kinds']) && is_array($input['pli_kinds'])) {
            $kinds = collect($input['pli_kinds'])->map(fn ($k) => mb_substr(trim((string) $k), 0, self::KIND_MAX))
                ->filter()->unique(fn ($k) => mb_strtolower($k))->values()->all();
            if ($kinds !== []) {
                $out['pli_kinds'] = $kinds;
            }
        }
        if (array_key_exists('forfait_usage', $input) && $input['forfait_usage'] !== null) {
            $out['forfait_usage'] = (bool) $input['forfait_usage'];
        }

        return $out;
    }
}
