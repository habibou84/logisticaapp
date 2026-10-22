<?php

namespace App\Models;

use App\Enums\PlatformFeature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Formule d'abonnement de la plateforme (forfait mensuel, courses incluses, dépassement,
 * livreurs, modules).
 */
class Plan extends Model
{
    protected $fillable = [
        'name',
        'description',
        'price_monthly',
        'included_orders',
        'overage_per_order',
        'max_couriers',
        'features',
        'is_active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'integer',
            'included_orders' => 'integer',
            'overage_per_order' => 'integer',
            'max_couriers' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function includes(PlatformFeature $feature): bool
    {
        return in_array($feature->value, $this->features ?? [], true);
    }

    /**
     * Formules proposées au départ (modifiables ensuite dans la console).
     *
     * @return list<array<string, mixed>>
     */
    public static function suggestions(): array
    {
        return [
            [
                'name' => 'Essentiel', 'description' => 'Pour démarrer : courses, livreurs, caisse et suivi.',
                'price_monthly' => 25000, 'included_orders' => 500, 'overage_per_order' => 30, 'max_couriers' => 5,
                'features' => [], 'position' => 1,
            ],
            [
                'name' => 'Pro', 'description' => 'Tous les modules pour une entreprise qui grandit.',
                'price_monthly' => 60000, 'included_orders' => 2000, 'overage_per_order' => 25, 'max_couriers' => 20,
                'features' => PlatformFeature::values(), 'position' => 2,
            ],
            [
                'name' => 'Entreprise', 'description' => 'Gros volumes, livreurs illimités.',
                'price_monthly' => 150000, 'included_orders' => 6000, 'overage_per_order' => 20, 'max_couriers' => null,
                'features' => PlatformFeature::values(), 'position' => 3,
            ],
        ];
    }
}
