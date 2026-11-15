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

    /** Ce qui n'est pas allé (liste déroulante du client ; obligatoire si la note est basse) */
    public const REASONS = [
        'late' => 'En retard',
        'always_late' => 'Toujours en retard',
        'not_on_time' => 'Ne livre pas à temps',
        'unreachable' => 'Injoignable',
        'no_answer' => 'Ne décroche pas',
        'never_calls_back' => 'Ne rappelle jamais',
        'rude' => 'Impoli',
        'discourteous' => 'Discourtois',
        'indiscreet' => 'Indiscret',
        'distracted' => 'Distrait',
        'hygiene' => 'Problème d\'hygiène',
        'damaged' => 'Colis abîmé',
        'instructions' => 'Consignes non respectées',
        'wrong_person' => 'Remis à une autre personne',
        'absent' => 'Absent ou parti avant l\'heure (MAD)',
        'other' => 'Autre',
    ];

    /** Équipe, note d'une course : ce qui n'est pas allé (liste déroulante ; obligatoire si la note est basse) */
    public const TEAM_REASONS = [
        'damaged_mail' => 'Courrier en mauvais état ou abîmé',
        'dirty_proof' => 'Décharge très sale',
        'wrong_delivery' => 'Erreur de livraison (mauvais destinataire)',
        'unreadable_stamp' => 'Cachet du destinataire illisible',
        'unsigned_proof' => 'Décharge non signée',
        'proof_not_returned' => 'Décharge non rapportée',
        'incomplete_proof' => 'Preuve de remise incomplète',
        'late' => 'Retard',
        'other' => 'Autre',
    ];

    /** Équipe : ce que veut dire chaque étoile sur une course */
    public const TEAM_STARS = [
        1 => ['Inacceptable', 'Faute grave : envoi perdu, abîmé ou remis à la mauvaise personne'],
        2 => ['Insuffisant', 'Plusieurs erreurs, preuve de remise mal faite'],
        3 => ['Correct', 'Livré, mais avec un petit souci (retard, décharge peu soignée)'],
        4 => ['Bien', 'Livré à temps, preuve complète'],
        5 => ['Excellent', 'Parfait : rapide, soigné, preuve impeccable'],
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
            'team_reasons' => $list(self::TEAM_REASONS),
            'team_stars' => collect(self::TEAM_STARS)->map(fn ($m, $n) => ['value' => $n, 'label' => $m[0], 'description' => $m[1]])->values(),
            'indicators' => $list(self::INDICATORS),
        ];
    }
}
