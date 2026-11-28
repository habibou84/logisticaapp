<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ZoneFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends Model
{
    /** @use HasFactory<ZoneFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = ['company_id', 'parent_id', 'place_code', 'name', 'city', 'is_shipping', 'in_forfait', 'shipping_fee_estimate', 'is_active', 'is_pickup', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_shipping' => 'boolean',
            'in_forfait' => 'boolean',
            'is_pickup' => 'boolean',
            'shipping_fee_estimate' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Lieu de la liste des lieux (commune, quartier, ville) : nom et rattachement fixés par la liste */
    public function isStandard(): bool
    {
        return $this->place_code !== null;
    }

    /**
     * Nom complet : « Cocody › Angré » pour un quartier.
     */
    public function fullName(): string
    {
        return $this->parent ? $this->parent->name.' › '.$this->name : $this->name;
    }

    /** L'entreprise collecte-t-elle ici ? (un quartier suit sa commune quand elle est coupée) */
    public function acceptsPickup(): bool
    {
        if (! $this->is_pickup) {
            return false;
        }

        // Commune pas chargée : on s'en tient au lieu lui-même (la création de course, elle, charge la commune)
        return $this->parent_id === null || ! $this->relationLoaded('parent') || ($this->parent?->is_pickup ?? true);
    }
}
