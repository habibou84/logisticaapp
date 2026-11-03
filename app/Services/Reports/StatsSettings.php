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
        // Alertes (chaque matin, cloche des administrateurs ; WhatsApp si demandé) ; null : pas d'alerte
        'alerts' => [
            'zone_success_min' => 80,
            'courier_failures_double' => true,
            'client_drop_percent' => 30,
            'min_volume' => 10,
            'whatsapp' => false,
        ],
        // Point par e-mail (synthèse et fichier Excel) : destinataires, chaque lundi, le 1er du mois
        'email' => ['recipients' => [], 'weekly' => false, 'monthly' => false],
    ];

    /** @return array<string, mixed> */
    public function for(?Company $company): array
    {
        $saved = self::clean((array) ($company?->stats_settings ?? []));

        return [...self::DEFAULTS, ...$saved, 'goals' => [...self::DEFAULTS['goals'], ...($saved['goals'] ?? [])],
            'alerts' => [...self::DEFAULTS['alerts'], ...($saved['alerts'] ?? [])],
            'email' => [...self::DEFAULTS['email'], ...($saved['email'] ?? [])]];
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'stats_settings' => ['sometimes', 'nullable', 'array:goals,renegotiate_margin,forfait_high,forfait_low,alerts,email'],
            'stats_settings.email' => ['nullable', 'array:recipients,weekly,monthly'],
            'stats_settings.email.recipients' => ['nullable', 'array', 'max:10'],
            'stats_settings.email.recipients.*' => ['email:rfc', 'max:150'],
            'stats_settings.email.weekly' => ['nullable', 'boolean'],
            'stats_settings.email.monthly' => ['nullable', 'boolean'],
            'stats_settings.alerts' => ['nullable', 'array:zone_success_min,courier_failures_double,client_drop_percent,min_volume,whatsapp'],
            'stats_settings.alerts.zone_success_min' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stats_settings.alerts.courier_failures_double' => ['nullable', 'boolean'],
            'stats_settings.alerts.client_drop_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'stats_settings.alerts.min_volume' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'stats_settings.alerts.whatsapp' => ['nullable', 'boolean'],
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
        if (isset($input['alerts']) && is_array($input['alerts'])) {
            $a = $input['alerts'];
            foreach (['zone_success_min', 'client_drop_percent'] as $k) {
                if (array_key_exists($k, $a)) {
                    $out['alerts'][$k] = $a[$k] === null || $a[$k] === '' ? null : (int) $a[$k];
                }
            }
            if (isset($a['min_volume']) && is_numeric($a['min_volume'])) {
                $out['alerts']['min_volume'] = max(1, (int) $a['min_volume']);
            }
            foreach (['courier_failures_double', 'whatsapp'] as $k) {
                if (array_key_exists($k, $a) && $a[$k] !== null) {
                    $out['alerts'][$k] = (bool) $a[$k];
                }
            }
        }
        if (isset($input['email']) && is_array($input['email'])) {
            $e = $input['email'];
            if (array_key_exists('recipients', $e)) {
                $out['email']['recipients'] = array_values(array_unique(array_filter(array_map(fn ($v) => mb_strtolower(trim((string) $v)), (array) $e['recipients']))));
            }
            foreach (['weekly', 'monthly'] as $k) {
                if (array_key_exists($k, $e) && $e[$k] !== null) {
                    $out['email'][$k] = (bool) $e[$k];
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
