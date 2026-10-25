<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Département d'un client (Finance, Marketing, Gestion…) : qui le dirige, où le trouver, son adresse s'il est
 * sur un autre site, son centre de coûts et son quota d'envois du mois ; les comptes des employés qui y sont
 * rattachés.
 */
class Department extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'merchant_id', 'name', 'code', 'manager_name', 'phone', 'email', 'location',
        'pickup_zone_id', 'pickup_address', 'pickup_landmark', 'pickup_lat', 'pickup_lng',
        'cost_center', 'monthly_quota', 'is_main', 'active', 'position', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'pickup_lat' => 'float',
            'pickup_lng' => 'float',
            'monthly_quota' => 'integer',
            'is_main' => 'boolean',
            'active' => 'boolean',
            'position' => 'integer',
        ];
    }

    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => PhoneNumber::normalize($value) ?? $value);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function pickupZone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'pickup_zone_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_main')->orderBy('position')->orderBy('name');
    }

    /** Sur un autre site que le client : sa propre adresse */
    public function hasOwnAddress(): bool
    {
        return $this->pickup_zone_id !== null;
    }

    /** « FIN · Finance » */
    public function label(): string
    {
        return $this->code ? "{$this->code} · {$this->name}" : $this->name;
    }
}
