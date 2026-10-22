<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Clé d'accès à l'API publique d'un marchand. Format : lv_<préfixe>_<secret>.
 * Seul le hash SHA-256 de la clé complète est conservé ; elle n'est affichée qu'à sa création.
 */
class ApiKey extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'merchant_id', 'name', 'client', 'key_prefix', 'key_hash', 'scopes', 'created_by', 'expires_at'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Crée une clé et renvoie la valeur en clair (à montrer une seule fois).
     *
     * Une clé d'application reliée en un clic ($client, ex. jibiat-marchand) marche quelle que soit la formule.
     *
     * @param  list<string>  $scopes
     * @return array{0: self, 1: string}
     */
    public static function issue(Merchant $merchant, string $name, array $scopes, ?User $creator, ?string $expiresAt = null, ?string $client = null): array
    {
        do {
            $prefix = Str::lower(Str::random(8));
        } while (static::withoutGlobalScopes()->where('key_prefix', $prefix)->exists());

        $plain = "lv_{$prefix}_".Str::random(40);

        $key = static::create([
            'company_id' => $merchant->company_id,
            'merchant_id' => $merchant->id,
            'name' => $name,
            'client' => $client,
            'key_prefix' => $prefix,
            'key_hash' => hash('sha256', $plain),
            'scopes' => array_values(array_unique($scopes)),
            'created_by' => $creator?->id,
            'expires_at' => $expiresAt,
        ]);

        return [$key, $plain];
    }

    /**
     * Retrouve une clé valide (non révoquée, non expirée) à partir de sa valeur en clair.
     */
    public static function findValid(string $plain): ?self
    {
        if (! preg_match('/^lv_([a-z0-9]{8})_[A-Za-z0-9]{40}$/', $plain, $m)) {
            return null;
        }

        $key = static::withoutGlobalScopes()->with('merchant')->where('key_prefix', $m[1])->first();

        if (! $key || ! hash_equals($key->key_hash, hash('sha256', $plain)) || ! $key->isUsable()) {
            return null;
        }

        return $key;
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class)->withoutGlobalScopes();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /**
     * Compte au nom duquel agit la clé : son créateur s'il est encore actif, sinon le gérant du marchand.
     */
    public function actor(): ?User
    {
        $users = User::withoutGlobalScopes()->where('merchant_id', $this->merchant_id)->where('status', 'active');

        return (clone $users)->whereKey($this->created_by)->first()
            ?? $users->role(Role::MerchantOwner->value)->orderBy('id')->first();
    }

    public function maskedKey(): string
    {
        return "lv_{$this->key_prefix}_••••••••";
    }
}
