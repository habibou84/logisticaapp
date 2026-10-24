<?php

namespace App\Services\Ratings;

use App\Models\Company;

/**
 * Réglages de la notation des livreurs, au choix de l'administrateur (Réglages › Notation des livreurs).
 */
class RatingSettings
{
    public const DEFAULTS = [
        // Poids des trois sources dans la note finale (en %)
        'weight_auto' => 40,
        'weight_client' => 35,
        'weight_team' => 25,
        // Période prise en compte (jours) et nombre de notes de clients avant d'afficher leur moyenne
        'window_days' => 90,
        'min_client_ratings' => 5,
        // Clients : noter chaque course (pendant n heures après la fin) ; MAD : note périodique du livreur attitré
        'client_course' => true,
        'client_edit_hours' => 48,
        'client_mad_period' => 'monthly',
        // Invitation WhatsApp au client après la livraison ; note du destinataire sur la page de suivi
        'whatsapp_invite' => false,
        'recipient_rating' => false,
        // Note basse (≤ ce nombre d'étoiles) : motif demandé et responsable prévenu
        'reason_below' => 2,
        // Course où le livreur a signalé un problème : poids de la note du client
        'incident_weight' => 0.5,
        // Ce que voit le livreur
        'courier_sees_score' => true,
        'courier_sees_comments' => true,
        'comments_anonymous' => true,
        // Alertes : note finale sous ce seuil ; mauvaises notes en 7 jours
        'alert_below' => 3.0,
        'alert_bad_count' => 3,
        // Usages : préférer les mieux notés à l'assignation ; livreurs préférés ou écartés par les clients
        'assignment_preference' => false,
        'client_choice' => false,
        // Indicateurs de la note automatique
        'auto_indicators' => ['delivery_success', 'pickup_on_time', 'internal_delay', 'reliability', 'cash', 'delivery_codes', 'proofs'],
    ];

    public const PERIODS = ['off', 'weekly', 'monthly'];

    /**
     * @return array<string, mixed>
     */
    public function for(?Company $company): array
    {
        $saved = $company?->rating_settings ?? [];

        return [...self::DEFAULTS, ...array_intersect_key(is_array($saved) ? $saved : [], self::DEFAULTS)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'rating_settings' => ['sometimes', 'nullable', 'array'],
            'rating_settings.weight_auto' => ['nullable', 'integer', 'between:0,100'],
            'rating_settings.weight_client' => ['nullable', 'integer', 'between:0,100'],
            'rating_settings.weight_team' => ['nullable', 'integer', 'between:0,100'],
            'rating_settings.window_days' => ['nullable', 'integer', 'between:7,365'],
            'rating_settings.min_client_ratings' => ['nullable', 'integer', 'between:1,100'],
            'rating_settings.client_course' => ['nullable', 'boolean'],
            'rating_settings.client_edit_hours' => ['nullable', 'integer', 'between:1,720'],
            'rating_settings.client_mad_period' => ['nullable', 'in:'.implode(',', self::PERIODS)],
            'rating_settings.whatsapp_invite' => ['nullable', 'boolean'],
            'rating_settings.recipient_rating' => ['nullable', 'boolean'],
            'rating_settings.reason_below' => ['nullable', 'integer', 'between:0,4'],
            'rating_settings.incident_weight' => ['nullable', 'numeric', 'between:0,1'],
            'rating_settings.courier_sees_score' => ['nullable', 'boolean'],
            'rating_settings.courier_sees_comments' => ['nullable', 'boolean'],
            'rating_settings.comments_anonymous' => ['nullable', 'boolean'],
            'rating_settings.alert_below' => ['nullable', 'numeric', 'between:0,5'],
            'rating_settings.alert_bad_count' => ['nullable', 'integer', 'between:0,50'],
            'rating_settings.assignment_preference' => ['nullable', 'boolean'],
            'rating_settings.client_choice' => ['nullable', 'boolean'],
            'rating_settings.auto_indicators' => ['nullable', 'array'],
            'rating_settings.auto_indicators.*' => ['in:'.implode(',', array_keys(RatingCriteria::INDICATORS))],
        ];
    }

    /**
     * Valeurs enregistrées : clés connues, valeurs vides retirées (valeur par défaut).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function clean(array $input): array
    {
        return array_filter(array_intersect_key($input, self::DEFAULTS), fn ($v) => $v !== null && $v !== '');
    }
}
