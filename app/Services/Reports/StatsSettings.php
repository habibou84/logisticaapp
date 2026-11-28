<?php

namespace App\Services\Reports;

use App\Enums\Activity;
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

    /**
     * Objectifs du mois par activité et pour les équipes (Statistiques › Objectifs) : indicateur => valeur visée.
     * Pour chaque indicateur : son libellé, son unité et son sens (une hausse est-elle une bonne nouvelle ?).
     */
    public const OBJECTIVES = [
        'activity' => [
            'revenue' => ['label' => 'Chiffre d\'affaires', 'kind' => 'money', 'higher' => true],
            'margin' => ['label' => 'Marge nette', 'kind' => 'money', 'higher' => true],
            'delivered' => ['label' => 'Courses livrées', 'kind' => 'number', 'higher' => true],
            'quality' => ['label' => 'Qualité', 'kind' => 'percent', 'higher' => true],
        ],
        'teams' => [
            'per_day' => ['label' => 'Livraisons par livreur et par jour', 'kind' => 'decimal', 'higher' => true],
            'success_rate' => ['label' => 'Taux de réussite', 'kind' => 'percent', 'higher' => true],
            'minutes' => ['label' => 'Temps moyen par mission', 'kind' => 'minutes', 'higher' => false],
            'pay_per_delivery' => ['label' => 'Paie par course livrée', 'kind' => 'money', 'higher' => false],
        ],
    ];

    /** Ce que visent les objectifs : chaque activité, puis les équipes */
    public const OBJECTIVE_SCOPES = ['ecommerce', 'distribution', 'mad', 'teams'];

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
            'email' => [...self::DEFAULTS['email'], ...($saved['email'] ?? [])],
            'objectives' => self::cleanObjectives((array) ($company?->stats_settings['objectives'] ?? [])),
            'exports' => self::cleanExports((array) ($company?->stats_settings['exports'] ?? []))];
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
     * Événements qui déclenchent une alerte (Statistiques › Outils › Alertes) : libellé, unité du seuil, seuil par
     * défaut (null : pas de seuil). Les trois premiers gardent leur réglage d'origine (alerts.*).
     */
    public const ALERT_EVENTS = [
        'zone_success' => ['label' => 'Une zone passe sous un taux de réussite', 'unit' => '% sur 7 jours', 'default' => 80],
        'courier_failures' => ['label' => 'Les échecs d\'un livreur doublent', 'unit' => null, 'default' => null],
        'client_drop' => ['label' => 'Un client baisse fortement', 'unit' => '% de baisse sur 30 jours', 'default' => 30],
        'forfait_over' => ['label' => 'Un forfait est dépassé', 'unit' => '% du forfait', 'default' => 100],
        'invoice_overdue' => ['label' => 'Une facture est en retard', 'unit' => 'jours de retard', 'default' => 30],
        'contract_ending' => ['label' => 'Un contrat arrive à échéance', 'unit' => 'jours avant', 'default' => 60],
        'objective_behind' => ['label' => 'Un objectif du mois est en retard', 'unit' => 'à partir du jour du mois', 'default' => 15],
        'cash_gap' => ['label' => 'Un écart de caisse est constaté', 'unit' => 'F sur 7 jours', 'default' => 10000],
        'lot_late' => ['label' => 'Un lot dépasse son échéance', 'unit' => null, 'default' => null],
    ];

    public const ALERT_CHANNELS = ['bell', 'whatsapp', 'email'];

    /**
     * Réglage de chaque événement : actif ?, seuil, canaux (cloche, WhatsApp, e-mail). Les trois alertes d'origine
     * lisent leur seuil dans alerts.* ; les nouvelles sont inactives tant qu'on ne les a pas activées.
     *
     * @return array<string, array{active: bool, threshold: int|float|null, bell: bool, whatsapp: bool, email: bool}>
     */
    public function alertEvents(?Company $company): array
    {
        $legacy = $this->for($company)['alerts'];
        $saved = (array) ($company?->stats_settings['alert_events'] ?? []);
        $origin = [
            'zone_success' => [$legacy['zone_success_min'] !== null, $legacy['zone_success_min']],
            'courier_failures' => [(bool) $legacy['courier_failures_double'], null],
            'client_drop' => [$legacy['client_drop_percent'] !== null, $legacy['client_drop_percent']],
        ];
        $out = [];
        foreach (self::ALERT_EVENTS as $key => $event) {
            $s = (array) ($saved[$key] ?? []);
            [$active, $threshold] = $origin[$key] ?? [(bool) ($s['active'] ?? false), $s['threshold'] ?? null];
            $out[$key] = [
                'active' => $active,
                'threshold' => $event['default'] === null ? null : ($threshold ?? $event['default']),
                'bell' => (bool) ($s['bell'] ?? true),
                'whatsapp' => (bool) ($s['whatsapp'] ?? $legacy['whatsapp']),
                'email' => (bool) ($s['email'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * Adresses qui reçoivent les alertes par e-mail (par défaut : celles du point par e-mail).
     *
     * @return list<string>
     */
    public function alertRecipients(?Company $company): array
    {
        $saved = $company?->stats_settings['alert_recipients'] ?? null;

        return is_array($saved) ? array_values($saved) : $this->for($company)['email']['recipients'];
    }

    /**
     * Enregistre les alertes : les trois d'origine écrivent aussi leur réglage d'origine, pour rester d'accord
     * avec lui.
     *
     * @param  array<string, array<string, mixed>>  $events
     * @param  list<string>  $recipients
     */
    public function storeAlerts(Company $company, array $events, array $recipients, ?int $minVolume = null): void
    {
        $settings = (array) ($company->stats_settings ?? []);
        $current = $this->alertEvents($company);
        $stored = [];
        foreach (self::ALERT_EVENTS as $key => $event) {
            $e = [...$current[$key], ...array_intersect_key((array) ($events[$key] ?? []), $current[$key])];
            $threshold = $event['default'] === null || ! is_numeric($e['threshold'] ?? null) ? $event['default'] : max(0, (int) $e['threshold']);
            $stored[$key] = ['active' => (bool) $e['active'], 'threshold' => $threshold, 'bell' => (bool) $e['bell'], 'whatsapp' => (bool) $e['whatsapp'], 'email' => (bool) $e['email']];
        }
        $alerts = [...$this->for($company)['alerts'],
            'zone_success_min' => $stored['zone_success']['active'] ? min(100, $stored['zone_success']['threshold']) : null,
            'courier_failures_double' => $stored['courier_failures']['active'],
            'client_drop_percent' => $stored['client_drop']['active'] ? max(1, min(100, $stored['client_drop']['threshold'])) : null,
            'whatsapp' => collect($stored)->contains(fn ($e) => $e['active'] && $e['whatsapp']),
        ];
        if ($minVolume !== null) {
            $alerts['min_volume'] = max(1, $minVolume);
        }
        $company->update(['stats_settings' => [...$settings, 'alerts' => $alerts, 'alert_events' => $stored,
            'alert_recipients' => array_values(array_unique(array_map(fn ($v) => mb_strtolower(trim((string) $v)), $recipients)))]]);
    }

    /** Modèles d'export enregistrés, au plus */
    public const MAX_EXPORTS = 20;

    /**
     * Options d'un export (Statistiques › Outils › Export avancé) : rubriques, format, activité, montants masqués,
     * livreurs anonymisés. Les valeurs inconnues sont écartées.
     *
     * @param  array<string, mixed>  $input
     * @return array{sections: list<string>, format: string, activity: string|null, hide_amounts: bool, anonymize: bool}
     */
    public static function cleanExportOptions(array $input): array
    {
        $sections = array_values(array_intersect(array_keys(StatsExport::SECTIONS), (array) ($input['sections'] ?? [])));

        return [
            'sections' => $sections ?: StatsExport::DEFAULT_SECTIONS,
            'format' => isset(StatsExport::FORMATS[$input['format'] ?? '']) ? $input['format'] : 'xlsx',
            'activity' => in_array($input['activity'] ?? null, Activity::values(), true) ? $input['activity'] : null,
            'hide_amounts' => (bool) ($input['hide_amounts'] ?? false),
            'anonymize' => (bool) ($input['anonymize'] ?? false),
        ];
    }

    /**
     * Modèles d'export enregistrés : un nom et des options, pour refaire le même export en un clic.
     *
     * @param  array<int, mixed>  $input
     * @return list<array<string, mixed>>
     */
    public static function cleanExports(array $input): array
    {
        return collect($input)->filter(fn ($t) => is_array($t) && is_string($t['id'] ?? null) && trim((string) ($t['name'] ?? '')) !== '')
            ->map(fn (array $t) => ['id' => mb_substr($t['id'], 0, 40), 'name' => mb_substr(trim((string) $t['name']), 0, 60), ...self::cleanExportOptions($t),
                // Envoi régulier par e-mail : chaque lundi, le 1er du mois, ou jamais
                'schedule' => in_array($t['schedule'] ?? null, ['weekly', 'monthly'], true) ? $t['schedule'] : null])
            ->take(self::MAX_EXPORTS)->values()->all();
    }

    /**
     * Objectifs : pour chaque activité et pour les équipes, la valeur visée de chaque indicateur (null : pas d'objectif).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array<string, int|float|null>>
     */
    public static function cleanObjectives(array $input): array
    {
        $out = [];
        foreach (self::OBJECTIVE_SCOPES as $scope) {
            foreach (self::OBJECTIVES[$scope === 'teams' ? 'teams' : 'activity'] as $key => $metric) {
                $v = $input[$scope][$key] ?? null;
                $out[$scope][$key] = $v === null || $v === '' || ! is_numeric($v) ? null
                    : (in_array($metric['kind'], ['percent', 'decimal'], true) ? round((float) $v, 1) : (int) $v);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function clean(array $input): array
    {
        $out = [];
        if (isset($input['objectives']) && is_array($input['objectives'])) {
            $out['objectives'] = self::cleanObjectives($input['objectives']);
        }
        if (isset($input['exports']) && is_array($input['exports'])) {
            $out['exports'] = self::cleanExports($input['exports']);
        }
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
