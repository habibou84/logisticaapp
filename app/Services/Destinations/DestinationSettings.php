<?php

namespace App\Services\Destinations;

use App\Models\Company;

/**
 * Réglages de l'annuaire des destinations, au choix de l'administrateur (Réglages › Annuaire).
 */
class DestinationSettings
{
    /** Ajout d'une destination saisie sur une course : directement, à valider par l'équipe, jamais */
    public const AUTO_ADD = ['on', 'pending', 'off'];

    /** Position par le livreur : quand il veut, proposée à chaque livraison, obligatoire si l'adresse n'en a pas */
    public const COURIER_LOCATE = ['free', 'prompt', 'required'];

    public const ACTIVITIES = ['distribution', 'mad', 'ecommerce'];

    public const DEFAULTS = [
        'enabled' => true,
        // Activités dont les courses proposent l'annuaire et l'enrichissent
        'activities' => ['distribution', 'mad'],
        // Suggestions aussi dans l'application des clients (sinon seulement l'équipe)
        'clients_see' => true,
        'auto_add' => 'on',
        // Destination choisie puis corrigée sur la course (numéro, adresse) : l'annuaire est mis à jour
        'auto_update' => true,
        'courier_locate' => 'prompt',
        // Précision minimale acceptée (m)
        'min_accuracy' => 60,
        // Écart avec la position déjà confirmée à partir duquel l'équipe tranche (m)
        'conflict_distance' => 150,
        // Une nouvelle position éloignée remplace l'ancienne sans attendre l'équipe
        'courier_overwrite' => false,
        // Position de la destination donnée au livreur sur les courses suivantes
        'use_location' => true,
    ];

    /**
     * @return array<string, mixed>
     */
    public function for(?Company $company): array
    {
        $saved = $company?->destination_settings ?? [];

        return [...self::DEFAULTS, ...self::clean(is_array($saved) ? $saved : [])];
    }

    /** L'annuaire sert-il pour cette activité ? */
    public function appliesTo(?Company $company, ?string $activity): bool
    {
        $settings = $this->for($company);

        return $settings['enabled'] && in_array($activity, $settings['activities'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'destination_settings' => ['sometimes', 'nullable', 'array'],
            'destination_settings.enabled' => ['nullable', 'boolean'],
            'destination_settings.activities' => ['nullable', 'array'],
            'destination_settings.activities.*' => ['in:'.implode(',', self::ACTIVITIES)],
            'destination_settings.clients_see' => ['nullable', 'boolean'],
            'destination_settings.auto_add' => ['nullable', 'in:'.implode(',', self::AUTO_ADD)],
            'destination_settings.auto_update' => ['nullable', 'boolean'],
            'destination_settings.courier_locate' => ['nullable', 'in:'.implode(',', self::COURIER_LOCATE)],
            'destination_settings.min_accuracy' => ['nullable', 'integer', 'between:5,500'],
            'destination_settings.conflict_distance' => ['nullable', 'integer', 'between:20,5000'],
            'destination_settings.courier_overwrite' => ['nullable', 'boolean'],
            'destination_settings.use_location' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function clean(array $input): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $input[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $value = match ($key) {
                'activities' => array_values(array_intersect(self::ACTIVITIES, (array) $value)),
                'auto_add' => in_array($value, self::AUTO_ADD, true) ? $value : null,
                'courier_locate' => in_array($value, self::COURIER_LOCATE, true) ? $value : null,
                'min_accuracy', 'conflict_distance' => (int) $value,
                default => (bool) $value,
            };
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
