<?php

namespace App\Services\FieldEvents;

use App\Models\Company;
use App\Models\FieldEvent;
use Illuminate\Support\Str;

/**
 * Réglages des événements terrain, au choix de l'administrateur (Réglages › Événements terrain) :
 * types proposés au livreur (libellé, icône, gravité, photo obligatoire, position, retards excusés)
 * et effets automatiques.
 */
class FieldEventSettings
{
    public const TYPES = [
        ['key' => 'breakdown', 'label' => 'Panne', 'icon' => '🛵', 'hint' => 'Moteur, crevaison, plus d\'essence', 'severity' => 'blocking', 'photo' => false, 'gps' => true, 'excuse' => true, 'active' => true],
        ['key' => 'accident', 'label' => 'Accident', 'icon' => '🚑', 'hint' => 'Avec ou sans blessé, colis abîmé', 'severity' => 'urgent', 'photo' => false, 'gps' => true, 'excuse' => true, 'active' => true],
        ['key' => 'control', 'label' => 'Contrôle', 'icon' => '👮', 'hint' => 'Police, fourrière', 'severity' => 'info', 'photo' => false, 'gps' => true, 'excuse' => true, 'active' => true],
        ['key' => 'theft', 'label' => 'Vol, agression', 'icon' => '🔒', 'hint' => 'Colis, argent, téléphone', 'severity' => 'urgent', 'photo' => false, 'gps' => true, 'excuse' => true, 'active' => true],
        ['key' => 'weather', 'label' => 'Météo, route', 'icon' => '🌧️', 'hint' => 'Pluie, inondation, route barrée, embouteillage', 'severity' => 'info', 'photo' => false, 'gps' => true, 'excuse' => true, 'active' => true],
        ['key' => 'equipment', 'label' => 'Matériel', 'icon' => '📱', 'hint' => 'Batterie, téléphone, sac de livraison', 'severity' => 'hindrance', 'photo' => false, 'gps' => false, 'excuse' => false, 'active' => true],
        ['key' => 'health', 'label' => 'Santé', 'icon' => '🤒', 'hint' => 'Malaise', 'severity' => 'blocking', 'photo' => false, 'gps' => true, 'excuse' => true, 'active' => true],
        ['key' => 'other', 'label' => 'Autre', 'icon' => '✏️', 'hint' => 'Dites-nous ce qui se passe', 'severity' => 'info', 'photo' => false, 'gps' => true, 'excuse' => false, 'active' => true],
    ];

    public const DEFAULTS = [
        'enabled' => true,
        'types' => self::TYPES,
        // Notes vocales (durée maximale en secondes)
        'voice_notes' => true,
        'voice_max_seconds' => 60,
        // Le livreur peut indiquer le coût de son dépannage
        'courier_cost' => true,
        // Livreur bloqué ou arrêté : mis hors service (plus de missions) jusqu'à sa reprise
        'pause_courier' => true,
        // Livreur bloqué ou arrêté : ses ramassages pas encore faits sont retirés et confiés à un autre (planning)
        'release_pickups' => false,
        // Clients des courses touchées prévenus d'un retard (message neutre, sans détail)
        'notify_clients' => false,
        // Retards des courses touchées excusés (note automatique, primes), pour les types qui le prévoient
        'excuse_delays' => true,
        // Journée arrêtée notée au planning (absence du jour)
        'mark_planning' => true,
        // Événement urgent : administrateurs prévenus tout de suite (sinon après les relances)
        'urgent_admins' => true,
    ];

    /**
     * @return array<string, mixed>
     */
    public function for(?Company $company): array
    {
        $saved = $company?->field_event_settings ?? [];
        $settings = [...self::DEFAULTS, ...array_intersect_key(is_array($saved) ? $saved : [], self::DEFAULTS)];
        $settings['types'] = array_values(array_map(fn ($t) => [...self::typeDefaults(), ...$t], (array) $settings['types']));

        return $settings;
    }

    /**
     * Types proposés au livreur (actifs).
     *
     * @return list<array<string, mixed>>
     */
    public function types(?Company $company): array
    {
        return array_values(array_filter($this->for($company)['types'], fn ($t) => $t['active']));
    }

    /** @return array<string, mixed>|null */
    public function type(?Company $company, string $key): ?array
    {
        return collect($this->for($company)['types'])->firstWhere('key', $key);
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'field_event_settings' => ['sometimes', 'nullable', 'array'],
            'field_event_settings.enabled' => ['nullable', 'boolean'],
            'field_event_settings.types' => ['nullable', 'array', 'min:1', 'max:30'],
            'field_event_settings.types.*.key' => ['nullable', 'string', 'max:40'],
            'field_event_settings.types.*.label' => ['required', 'string', 'max:60'],
            'field_event_settings.types.*.icon' => ['nullable', 'string', 'max:16'],
            'field_event_settings.types.*.hint' => ['nullable', 'string', 'max:120'],
            'field_event_settings.types.*.severity' => ['required', 'in:'.implode(',', FieldEvent::SEVERITIES)],
            'field_event_settings.types.*.photo' => ['nullable', 'boolean'],
            'field_event_settings.types.*.gps' => ['nullable', 'boolean'],
            'field_event_settings.types.*.excuse' => ['nullable', 'boolean'],
            'field_event_settings.types.*.active' => ['nullable', 'boolean'],
            'field_event_settings.voice_notes' => ['nullable', 'boolean'],
            'field_event_settings.voice_max_seconds' => ['nullable', 'integer', 'between:10,180'],
            'field_event_settings.courier_cost' => ['nullable', 'boolean'],
            'field_event_settings.pause_courier' => ['nullable', 'boolean'],
            'field_event_settings.release_pickups' => ['nullable', 'boolean'],
            'field_event_settings.notify_clients' => ['nullable', 'boolean'],
            'field_event_settings.excuse_delays' => ['nullable', 'boolean'],
            'field_event_settings.mark_planning' => ['nullable', 'boolean'],
            'field_event_settings.urgent_admins' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Valeurs enregistrées : clés connues, valeurs vides retirées (valeur par défaut) ; types nettoyés,
     * chacun avec une clé unique (tirée du libellé pour un type ajouté).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function clean(array $input): array
    {
        $clean = array_filter(array_intersect_key($input, self::DEFAULTS), fn ($v) => $v !== null && $v !== '');

        if (isset($clean['types'])) {
            // Données validées : les lignes peuvent arriver dans le désordre (champs facultatifs)
            $types = (array) $clean['types'];
            ksort($types);
            $keys = [];
            $clean['types'] = array_values(array_map(function (array $t) use (&$keys) {
                $key = Str::limit(Str::slug((string) ($t['key'] ?? '') ?: (string) $t['label'], '_'), 36, '') ?: 'type';
                for ($base = $key, $i = 2; in_array($key, $keys, true); $i++) {
                    $key = "{$base}_{$i}";
                }
                $keys[] = $key;

                return [
                    'key' => $key,
                    'label' => trim((string) $t['label']),
                    'icon' => trim((string) ($t['icon'] ?? '')) ?: '⚠️',
                    'hint' => trim((string) ($t['hint'] ?? '')),
                    'severity' => $t['severity'],
                    'photo' => (bool) ($t['photo'] ?? false),
                    'gps' => (bool) ($t['gps'] ?? true),
                    'excuse' => (bool) ($t['excuse'] ?? false),
                    'active' => (bool) ($t['active'] ?? true),
                ];
            }, $types));
        }

        return $clean;
    }

    /** @return array<string, mixed> */
    private static function typeDefaults(): array
    {
        return ['icon' => '⚠️', 'hint' => '', 'severity' => 'info', 'photo' => false, 'gps' => true, 'excuse' => false, 'active' => true];
    }
}
