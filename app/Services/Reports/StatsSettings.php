<?php

namespace App\Services\Reports;

use App\Models\Company;

/**
 * Réglages des statistiques (Statistiques › Objectifs et Rentabilité) :
 * - objectifs du mois (courses livrées, chiffre d'affaires, taux de réussite, marge nette) ; vide : pas d'objectif ;
 * - client « à renégocier » sous ce taux de marge ; « à perte » sous zéro ;
 * - forfait « à revoir à la hausse » au-delà de ce taux d'utilisation, « sous-utilisé » en dessous de l'autre.
 */
class StatsSettings
{
    public const GOALS = ['delivered', 'revenue', 'success_rate', 'margin'];

    public const DEFAULTS = [
        'goals' => ['delivered' => null, 'revenue' => null, 'success_rate' => null, 'margin' => null],
        'renegotiate_margin' => 10,
        'forfait_high' => 100,
        'forfait_low' => 50,
    ];

    /** @return array<string, mixed> */
    public function for(?Company $company): array
    {
        $saved = self::clean((array) ($company?->stats_settings ?? []));

        return [...self::DEFAULTS, ...$saved, 'goals' => [...self::DEFAULTS['goals'], ...($saved['goals'] ?? [])]];
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'stats_settings' => ['sometimes', 'nullable', 'array:goals,renegotiate_margin,forfait_high,forfait_low'],
            'stats_settings.goals' => ['nullable', 'array:'.implode(',', self::GOALS)],
            'stats_settings.goals.delivered' => ['nullable', 'integer', 'min:0'],
            'stats_settings.goals.revenue' => ['nullable', 'integer', 'min:0'],
            'stats_settings.goals.success_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stats_settings.goals.margin' => ['nullable', 'integer'],
            'stats_settings.renegotiate_margin' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stats_settings.forfait_high' => ['nullable', 'integer', 'min:50', 'max:300'],
            'stats_settings.forfait_low' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function clean(array $input): array
    {
        $out = [];
        if (isset($input['goals']) && is_array($input['goals'])) {
            foreach (self::GOALS as $key) {
                if (array_key_exists($key, $input['goals'])) {
                    $v = $input['goals'][$key];
                    $out['goals'][$key] = $v === null || $v === '' ? null : ($key === 'success_rate' ? (float) $v : (int) $v);
                }
            }
        }
        foreach (['renegotiate_margin', 'forfait_high', 'forfait_low'] as $key) {
            if (isset($input[$key]) && is_numeric($input[$key])) {
                $out[$key] = $key === 'renegotiate_margin' ? (float) $input[$key] : (int) $input[$key];
            }
        }

        return $out;
    }
}
