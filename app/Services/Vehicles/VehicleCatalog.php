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
        'insurance' => ['label' => 'Assurance', 'icon' => '🛡️'],
        'inspection' => ['label' => 'Visite technique', 'icon' => '🔎'],
        'tax' => ['label' => 'Vignette', 'icon' => '🧾'],
        'registration' => ['label' => 'Carte grise', 'icon' => '📗'],
        'transport' => ['label' => 'Carte de transport', 'icon' => '🚦'],
        'license' => ['label' => 'Permis de conduire', 'icon' => '🪪'],
        'other' => ['label' => 'Autre papier', 'icon' => '📄'],
    ];

    public const CHECK_ITEMS = [
        'brakes' => ['label' => 'Freins', 'icon' => '🛑'],
        'tires' => ['label' => 'Pneus', 'icon' => '🛞'],
        'lights' => ['label' => 'Phares, clignotants', 'icon' => '💡'],
        'mirrors' => ['label' => 'Rétroviseurs', 'icon' => '🪞'],
        'box' => ['label' => 'Coffre fermé à clé', 'icon' => '📦'],
        'horn' => ['label' => 'Klaxon', 'icon' => '📯'],
        'chain' => ['label' => 'Chaîne', 'icon' => '⛓️'],
        'papers' => ['label' => 'Papiers à bord', 'icon' => '📄'],
        'helmet' => ['label' => 'Casque', 'icon' => '⛑️'],
    ];

    public const FUEL_LEVELS = ['reserve' => 'Réserve', 'quarter' => '1/4', 'half' => '1/2', 'three_quarters' => '3/4', 'full' => 'Plein'];

    public const PLANS = [
        'moto' => [
            ['key' => 'oil', 'label' => 'Vidange', 'icon' => '🛢️', 'every_km' => 2000, 'every_months' => null],
            ['key' => 'tires', 'label' => 'Pneus', 'icon' => '🛞', 'every_km' => 12000, 'every_months' => null],
            ['key' => 'brakes', 'label' => 'Plaquettes de frein', 'icon' => '🛑', 'every_km' => null, 'every_months' => 6],
            ['key' => 'chain', 'label' => 'Kit chaîne', 'icon' => '⛓️', 'every_km' => 15000, 'every_months' => null],
        ],
        'tricycle' => [
            ['key' => 'oil', 'label' => 'Vidange', 'icon' => '🛢️', 'every_km' => 3000, 'every_months' => null],
            ['key' => 'tires', 'label' => 'Pneus', 'icon' => '🛞', 'every_km' => 15000, 'every_months' => null],
            ['key' => 'brakes', 'label' => 'Freins', 'icon' => '🛑', 'every_km' => null, 'every_months' => 6],
        ],
        'voiture' => [
            ['key' => 'oil', 'label' => 'Vidange', 'icon' => '🛢️', 'every_km' => 7500, 'every_months' => 12],
            ['key' => 'tires', 'label' => 'Pneus', 'icon' => '🛞', 'every_km' => 40000, 'every_months' => null],
            ['key' => 'brakes', 'label' => 'Freins', 'icon' => '🛑', 'every_km' => 20000, 'every_months' => null],
            ['key' => 'service', 'label' => 'Révision', 'icon' => '🔧', 'every_km' => null, 'every_months' => 12],
        ],
        'velo' => [
            ['key' => 'tires', 'label' => 'Pneus et chambres à air', 'icon' => '🛞', 'every_km' => null, 'every_months' => 6],
            ['key' => 'brakes', 'label' => 'Freins', 'icon' => '🛑', 'every_km' => null, 'every_months' => 6],
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
