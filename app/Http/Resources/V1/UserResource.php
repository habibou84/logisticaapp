<?php

namespace App\Http\Resources\V1;

use App\Models\SupportSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

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
        $role = $this->primaryRole();

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'role' => $role?->value,
            'role_label' => $role?->label(),
            'status' => $this->status,
            'last_login_at' => $this->last_login_at,
            'company' => CompanyResource::make($this->whenLoaded('company')),
            'merchant_id' => $this->merchant_id,
            'merchant' => $this->whenLoaded('merchant', fn () => $this->merchant ? [
                'id' => $this->merchant->id,
                'business_name' => $this->merchant->business_name,
                // Rappel dans l'application tant que le lieu de ramassage n'est pas localisé
                'has_pickup_location' => $this->merchant->hasPickupLocation(),
                'pickup_location_source' => $this->merchant->pickup_location_source,
            ] : null),
            'courier' => $this->whenLoaded('courier', fn () => $this->courier ? [
                'id' => $this->courier->id,
                'is_available' => $this->courier->is_available,
                'vehicle_type' => $this->courier->vehicle_type,
            ] : null),
            // Session d'assistance ouverte par le super administrateur (bandeau dans l'espace)
            'support_session' => $this->when($this->resource->is($request->user()), fn () => $this->supportSession()),
            'permissions' => $this->when(
                $this->withPermissions,
                fn () => $this->getAllPermissions()->pluck('name')->values(),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @return array{opened_by: ?string, expires_at: mixed}|null
     */
    private function supportSession(): ?array
    {
        $token = $this->resource->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || ! str_starts_with($token->name, SupportSession::TOKEN_PREFIX)) {
            return null;
        }

        return [
            'opened_by' => User::query()->withoutGlobalScopes()->whereKey((int) substr($token->name, strlen(SupportSession::TOKEN_PREFIX)))->value('name'),
            'expires_at' => $token->expires_at,
        ];
    }
}
