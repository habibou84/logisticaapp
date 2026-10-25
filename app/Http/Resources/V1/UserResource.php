<?php

namespace App\Http\Resources\V1;

use App\Enums\Activity;
use App\Models\BillingPlan;
use App\Models\User;
use App\Services\Couriers\CourierCapabilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    private bool $withPermissions = false;

    /**
     * Inclut la liste des permissions (utile au front-end pour l'utilisateur connecté).
     */
    public function withPermissions(): static
    {
        $this->withPermissions = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->accessRole();

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'role' => $role?->name,
            'role_label' => $role?->displayLabel(),
            // Rôle sur mesure de l'équipe (écran Rôles)
            'role_custom' => (bool) $role?->is_custom,
            // Espace de l'application : équipe, livreur ou client
            'space' => $this->space(),
            'status' => $this->status,
            'last_login_at' => $this->last_login_at,
            'company' => CompanyResource::make($this->whenLoaded('company')),
            'merchant_id' => $this->merchant_id,
            'merchant' => $this->whenLoaded('merchant', fn () => $this->merchant ? [
                'id' => $this->merchant->id,
                'business_name' => $this->merchant->business_name,
                // Activités et formules en cours (distribution, MAD) : activité, type d'envoi et délai sur ses courses
                'activities' => array_map(fn (Activity $a) => $a->value, $this->merchant->activityList()),
                'billing_plans' => collect([Activity::Distribution, Activity::Mad])
                    ->map(fn (Activity $a) => BillingPlan::activeFor($this->merchant, $a)?->brief())->filter()->values(),
                // Lots ponctuels ouverts : choisis sur la course
                'lots' => BillingPlan::openLotsFor($this->merchant)->map(fn (BillingPlan $p) => $p->brief())->values(),
                // Rappel dans l'application tant que le lieu de ramassage n'est pas localisé
                'has_pickup_location' => $this->merchant->hasPickupLocation(),
                'pickup_location_source' => $this->merchant->pickup_location_source,
            ] : null),
            'courier' => $this->whenLoaded('courier', fn () => $this->courier ? [
                'id' => $this->courier->id,
                'is_available' => $this->courier->is_available,
                'vehicle_type' => $this->courier->vehicle_type,
                // Ce que l'application livreur permet : hors connexion, gain prévu
                'can' => app(CourierCapabilities::class)->for($this->courier),
            ] : null),
            // Équipe : périmètre (activités visibles, vide : toutes) et réglage fin des permissions
            'activities' => $this->activities ?? [],
            'zone_ids' => $this->zone_ids ?? [],
            'hub_ids' => $this->hub_ids ?? [],
            // Périmètre effectif (compte, sinon rôle) : vide = tout
            'scope' => [
                'activities' => $this->activityScope() ?? [],
                'zone_ids' => $this->resource->zoneScope() ?? [],
                'hub_ids' => $this->resource->hubScope() ?? [],
            ],
            'extra_permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')->values()),
            'denied_permissions' => $this->denied_permissions ?? [],
            'permissions' => $this->when(
                $this->withPermissions,
                fn () => $this->resource->effectivePermissions(),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
