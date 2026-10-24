<?php

namespace App\Services\Ratings;

/**
 * Critères des notes et indicateurs de la note automatique.
 */
class RatingCriteria
{
    /** Client : critères en un clic (bon point) */
    public const CLIENT = [
        'punctuality' => 'Ponctuel',
        'politeness' => 'Poli, bonne présentation',
        'care' => 'Colis en bon état',
        'communication' => 'A prévenu, joignable',
        'instructions' => 'A respecté les consignes',
        'handover' => 'Remis à la bonne personne, décharge rapportée',
        'presence' => 'Présent aux horaires, disponible',
    ];

    /** Note basse : motif */
    public const REASONS = [
        'late' => 'En retard',
        'rude' => 'Impoli',
        'damaged' => 'Colis abîmé',
        'unreachable' => 'Injoignable',
        'instructions' => 'Consignes non respectées',
        'wrong_person' => 'Remis à une autre personne',
        'absent' => 'Absent ou parti avant l\'heure (MAD)',
        'other' => 'Autre',
    ];

    /** Équipe : critères notés de 1 à 5 */
    public const TEAM = [
        'procedures' => 'Respect des procédures (preuves, codes)',
        'cash' => 'Fiabilité de la caisse',
        'reactivity' => 'Réactivité aux consignes',
        'equipment' => 'Tenue et matériel',
        'behaviour' => 'Esprit d\'équipe, comportement',
    ];

    /** Note automatique : indicateurs (sur 100) */
    public const INDICATORS = [
        'delivery_success' => 'Livraisons réussies',
        'pickup_on_time' => 'Ramassages à l\'heure',
        'internal_delay' => 'Délai interne respecté (distribution)',
        'reliability' => 'Missions acceptées, livreur joignable',
        'cash' => 'Caisse sans manque, aucun colis perdu',
        'delivery_codes' => 'Codes de livraison vérifiés',
        'proofs' => 'Preuves de remise complètes',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $list = fn (array $items) => collect($items)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values();

        return [
            'client' => $list(self::CLIENT),
            'reasons' => $list(self::REASONS),
            'team' => $list(self::TEAM),
            'indicators' => $list(self::INDICATORS),
        ];
    }
}
