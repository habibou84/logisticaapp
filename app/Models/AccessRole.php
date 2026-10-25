<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Rôle (table « roles ») : rôle intégré défini dans App\Enums\Role, ou rôle sur mesure de l'entreprise créé par
 * l'administrateur (écran Rôles) avec son périmètre par défaut.
 */
class AccessRole extends SpatieRole
{
    protected function casts(): array
    {
        return [
            'is_custom' => 'boolean',
            'activities' => 'array',
            'zone_ids' => 'array',
            'hub_ids' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeCustomFor(Builder $query, ?int $companyId): Builder
    {
        return $query->where('is_custom', true)->where('company_id', $companyId);
    }

    /** Rôle intégré correspondant, null pour un rôle sur mesure */
    public function builtIn(): ?Role
    {
        return $this->is_custom ? null : Role::tryFrom($this->name);
    }

    public function displayLabel(): string
    {
        return $this->is_custom ? (string) $this->label : ($this->builtIn()?->label() ?? $this->name);
    }
}
