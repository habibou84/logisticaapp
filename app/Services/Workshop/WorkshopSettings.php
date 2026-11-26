<?php

namespace App\Services\Workshop;

use App\Models\Company;

/**
 * Réglages de l'atelier : attribution automatique des pannes aux mécaniciens, délai pour accepter une proposition,
 * événements terrain qui demandent un mécanicien, types de panne proposés au diagnostic.
 */
class WorkshopSettings
{
    public const CATEGORIES = [
        ['key' => 'engine', 'label' => 'Moteur', 'icon' => 'moteur'],
        ['key' => 'electric', 'label' => 'Électrique', 'icon' => 'batterie'],
        ['key' => 'tyre', 'label' => 'Pneu', 'icon' => 'pneu'],
        ['key' => 'brakes', 'label' => 'Freins', 'icon' => 'freins'],
        ['key' => 'chain', 'label' => 'Chaîne, transmission', 'icon' => 'chaine'],
        ['key' => 'fuel', 'label' => 'Carburant', 'icon' => 'carburant'],
        ['key' => 'clutch', 'label' => 'Embrayage', 'icon' => 'atelier'],
        ['key' => 'lights', 'label' => 'Éclairage', 'icon' => 'eclairage'],
        ['key' => 'body', 'label' => 'Carrosserie, accident', 'icon' => 'carrosserie'],
        ['key' => 'other', 'label' => 'Autre', 'icon' => 'modifier'],
    ];

    public const DEFAULTS = [
        // Les pannes signalées sont confiées automatiquement au mécanicien le plus proche
        'auto_assign' => true,
        // Minutes pour accepter une proposition avant qu'elle passe au mécanicien suivant
        'offer_minutes' => 5,
        // Événements terrain qui demandent un mécanicien
        'event_types' => ['breakdown', 'accident'],
        'categories' => self::CATEGORIES,
    ];

    /**
     * @return array<string, mixed>
     */
    public function for(?Company $company): array
    {
        $saved = $company?->workshop_settings ?? [];

        return [...self::DEFAULTS, ...array_intersect_key(is_array($saved) ? $saved : [], self::DEFAULTS)];
    }

    /** @return array{key: string, label: string, icon: string}|null */
    public function category(?Company $company, ?string $key): ?array
    {
        return $key === null ? null : collect($this->for($company)['categories'])->firstWhere('key', $key);
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'auto_assign' => ['sometimes', 'boolean'],
            'offer_minutes' => ['sometimes', 'integer', 'between:1,60'],
            'event_types' => ['sometimes', 'array'],
            'event_types.*' => ['string', 'max:40'],
        ];
    }
}
