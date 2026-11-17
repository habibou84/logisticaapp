<?php

namespace App\Services\Shifts;

use App\Models\Company;

/**
 * Réglages du pointage des livreurs, au choix de l'administrateur (Réglages › Pointage) :
 * - agence de l'entreprise (nom, adresse, position) : lieu de pointage des livreurs de distribution et d'e-commerce ;
 * - agency_start, agency_days : heure de début et jours de travail à l'agence ;
 * - radius : distance autorisée autour du lieu (m) ; min_accuracy : précision GPS demandée (m) ;
 *   le livreur est accepté si sa distance, moins l'imprécision de son GPS, reste dans le rayon ;
 * - early_minutes : pointage possible à partir de ce délai avant l'heure prévue ;
 * - late_tolerance : retard noté au-delà ; alert_minutes : dispatch prévenu si pas de pointage après ce délai (0 = jamais).
 */
class ShiftSettings
{
    public const DEFAULTS = [
        'enabled' => true,
        'agency_name' => 'Agence',
        'agency_address' => null,
        'agency_lat' => null,
        'agency_lng' => null,
        'agency_start' => '08:00',
        'agency_days' => [1, 2, 3, 4, 5, 6],
        'radius' => 50,
        'min_accuracy' => 30,
        'early_minutes' => 30,
        'late_tolerance' => 5,
        'alert_minutes' => 15,
    ];

    /**
     * @return array<string, mixed>
     */
    public function for(?Company $company): array
    {
        $saved = $company?->shift_settings ?? [];

        return [...self::DEFAULTS, ...self::clean(is_array($saved) ? $saved : [])];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'shift_settings' => ['sometimes', 'nullable', 'array'],
            'shift_settings.enabled' => ['nullable', 'boolean'],
            'shift_settings.agency_name' => ['nullable', 'string', 'max:120'],
            'shift_settings.agency_address' => ['nullable', 'string', 'max:255'],
            'shift_settings.agency_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:shift_settings.agency_lng'],
            'shift_settings.agency_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:shift_settings.agency_lat'],
            'shift_settings.agency_start' => ['nullable', 'date_format:H:i'],
            'shift_settings.agency_days' => ['nullable', 'array'],
            'shift_settings.agency_days.*' => ['integer', 'between:1,7'],
            'shift_settings.radius' => ['nullable', 'integer', 'between:10,2000'],
            'shift_settings.min_accuracy' => ['nullable', 'integer', 'between:5,500'],
            'shift_settings.early_minutes' => ['nullable', 'integer', 'between:0,600'],
            'shift_settings.late_tolerance' => ['nullable', 'integer', 'between:0,240'],
            'shift_settings.alert_minutes' => ['nullable', 'integer', 'between:0,600'],
        ];
    }

    /**
     * Valeurs enregistrées : clés connues, types justes ; une valeur vide reprend celle par défaut.
     *
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
            $out[$key] = match ($key) {
                'enabled' => (bool) $value,
                'agency_name', 'agency_address' => mb_substr(trim((string) $value), 0, $key === 'agency_name' ? 120 : 255),
                'agency_lat', 'agency_lng' => round((float) $value, 7),
                'agency_start' => (string) $value,
                'agency_days' => collect((array) $value)->map(fn ($d) => (int) $d)->filter(fn ($d) => $d >= 1 && $d <= 7)->unique()->sort()->values()->all(),
                default => (int) $value,
            };
        }
        if (! isset($out['agency_lat'], $out['agency_lng'])) {
            unset($out['agency_lat'], $out['agency_lng']);
        }

        return $out;
    }
}
