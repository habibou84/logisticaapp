<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\Destinations\DestinationPhones;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Destination de l'annuaire (partagé par tous les clients de distribution et de MAD).
 */
class Destination extends Model
{
    use BelongsToCompany;

    public const SOURCES = ['import', 'order', 'staff'];

    protected $fillable = [
        'company_id', 'name', 'acronym', 'city', 'zone_id', 'district', 'address', 'landmark',
        'phone', 'phone2', 'phone3', 'phones_raw', 'phone_check', 'email', 'sector',
        'lat', 'lng', 'location_accuracy', 'location_source', 'located_at', 'located_by', 'location_conflict',
        'deliveries_count', 'last_used_at', 'source', 'status', 'legacy_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'phone_check' => 'boolean',
            'lat' => 'float',
            'lng' => 'float',
            'location_accuracy' => 'integer',
            'located_at' => 'datetime',
            'location_conflict' => 'boolean',
            'deliveries_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Texte de recherche tenu à jour
        static::saving(fn (self $d) => $d->search = $d->searchText());
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function locator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'located_by');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(DestinationLocation::class)->latest('id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** @return list<string> */
    public function phones(): array
    {
        return array_values(array_filter([$this->phone, $this->phone2, $this->phone3]));
    }

    public function hasLocation(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    /** « ITWA · Imperial Tobacco West Africa » */
    public function label(): string
    {
        return $this->acronym ? "{$this->acronym} · {$this->name}" : $this->name;
    }

    public function searchText(): string
    {
        $phones = array_map(fn ($p) => substr((string) $p, -10), $this->phones());

        return self::normalize(implode(' ', array_filter([
            $this->acronym, $this->name, $this->district, $this->address, $this->landmark, $this->city,
            implode(' ', $phones), DestinationPhones::digits((string) $this->phones_raw),
        ])));
    }

    public static function normalize(?string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii((string) $value))));
    }
}
