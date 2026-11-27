<?php

namespace App\Services\Vehicles;

use App\Models\Vehicle;
use App\Models\VehicleLog;

/**
 * Listes de la gestion des véhicules : papiers, points du contrôle rapide, niveaux d'essence, plans d'entretien
 * proposés par type de véhicule.
 */
class VehicleCatalog
{
    public const DOCUMENTS = [
        'insurance' => ['label' => 'Assurance', 'icon' => 'assurance'],
        'inspection' => ['label' => 'Visite technique', 'icon' => 'visite-technique'],
        'tax' => ['label' => 'Vignette', 'icon' => 'vignette'],
        'registration' => ['label' => 'Carte grise', 'icon' => 'carte-grise'],
        'transport' => ['label' => 'Carte de transport', 'icon' => 'carte-transport'],
        'license' => ['label' => 'Permis de conduire', 'icon' => 'permis'],
        'other' => ['label' => 'Autre papier', 'icon' => 'document'],
    ];

    public const CHECK_ITEMS = [
        'brakes' => ['label' => 'Freins', 'icon' => 'freins'],
        'tires' => ['label' => 'Pneus', 'icon' => 'pneu'],
        'lights' => ['label' => 'Phares, clignotants', 'icon' => 'eclairage'],
        'mirrors' => ['label' => 'Rétroviseurs', 'icon' => 'retroviseur'],
        'box' => ['label' => 'Coffre fermé à clé', 'icon' => 'coffre'],
        'horn' => ['label' => 'Klaxon', 'icon' => 'son'],
        'chain' => ['label' => 'Chaîne', 'icon' => 'chaine'],
        'papers' => ['label' => 'Papiers à bord', 'icon' => 'document'],
        'helmet' => ['label' => 'Casque', 'icon' => 'casque'],
    ];

    public const FUEL_LEVELS = ['reserve' => 'Réserve', 'quarter' => '1/4', 'half' => '1/2', 'three_quarters' => '3/4', 'full' => 'Plein'];

    public const PLANS = [
        'moto' => [
            ['key' => 'oil', 'label' => 'Vidange', 'icon' => 'vidange', 'every_km' => 2000, 'every_months' => null],
            ['key' => 'tires', 'label' => 'Pneus', 'icon' => 'pneu', 'every_km' => 12000, 'every_months' => null],
            ['key' => 'brakes', 'label' => 'Plaquettes de frein', 'icon' => 'freins', 'every_km' => null, 'every_months' => 6],
            ['key' => 'chain', 'label' => 'Kit chaîne', 'icon' => 'chaine', 'every_km' => 15000, 'every_months' => null],
        ],
        'tricycle' => [
            ['key' => 'oil', 'label' => 'Vidange', 'icon' => 'vidange', 'every_km' => 3000, 'every_months' => null],
            ['key' => 'tires', 'label' => 'Pneus', 'icon' => 'pneu', 'every_km' => 15000, 'every_months' => null],
            ['key' => 'brakes', 'label' => 'Freins', 'icon' => 'freins', 'every_km' => null, 'every_months' => 6],
        ],
        'voiture' => [
            ['key' => 'oil', 'label' => 'Vidange', 'icon' => 'vidange', 'every_km' => 7500, 'every_months' => 12],
            ['key' => 'tires', 'label' => 'Pneus', 'icon' => 'pneu', 'every_km' => 40000, 'every_months' => null],
            ['key' => 'brakes', 'label' => 'Freins', 'icon' => 'freins', 'every_km' => 20000, 'every_months' => null],
            ['key' => 'service', 'label' => 'Révision', 'icon' => 'atelier', 'every_km' => null, 'every_months' => 12],
        ],
        'velo' => [
            ['key' => 'tires', 'label' => 'Pneus et chambres à air', 'icon' => 'pneu', 'every_km' => null, 'every_months' => 6],
            ['key' => 'brakes', 'label' => 'Freins', 'icon' => 'freins', 'every_km' => null, 'every_months' => 6],
        ],
        'pieton' => [],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $list = fn (array $items) => collect($items)->map(fn ($v, $k) => ['value' => $k, ...(is_array($v) ? $v : ['label' => $v])])->values();

        return [
            'documents' => $list(self::DOCUMENTS),
            'check_items' => $list(self::CHECK_ITEMS),
            'fuel_levels' => $list(self::FUEL_LEVELS),
            'statuses' => $list(Vehicle::STATUSES),
            'ownerships' => $list(Vehicle::OWNERSHIPS),
            'log_kinds' => collect(VehicleLog::KINDS)->map(fn ($label, $k) => ['value' => $k, 'label' => $label, 'icon' => VehicleLog::ICONS[$k]])->values(),
        ];
    }
}
