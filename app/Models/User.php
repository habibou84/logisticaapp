<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Support\PhoneNumber;
use App\Support\Tenancy;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    // Les rôles et permissions sont globaux (non liés à une entreprise) :
    // l'isolation entre entreprises se fait par company_id.
    protected string $guard_name = 'web';

    protected $fillable = [
        'company_id',
        'merchant_id',
        'name',
        'phone',
        'email',
        'password',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'setup_seen_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    protected function phone(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => PhoneNumber::normalize($value) ?? $value,
        );
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)),
        );
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function courier(): HasOne
    {
        return $this->hasOne(Courier::class);
    }

    public function scopeForCompany(Builder $query, ?int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Rôle principal de l'utilisateur (un seul rôle par compte).
     */
    public function primaryRole(): ?Role
    {
        $name = $this->getRoleNames()->first();

        return $name ? Role::tryFrom($name) : null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SuperAdmin->value);
    }

    /**
     * Crée le profil livreur (véhicule, zones, disponibilité) si l'utilisateur
     * a le rôle livreur et n'en a pas encore.
     */
    public function syncCourierProfile(): void
    {
        if ($this->isCourier() && $this->company_id !== null) {
            Courier::withoutGlobalScopes()->firstOrCreate(
                ['user_id' => $this->id],
                ['company_id' => $this->company_id],
            );
            $this->unsetRelation('courier');
        }
    }

    public function isCourier(): bool
    {
        return $this->hasRole(Role::Courier->value);
    }

    /**
     * Personnel de l'entreprise de livraison (back-office) ou super administrateur.
     */
    public function isStaff(): bool
    {
        return $this->hasAnyRole([Role::SuperAdmin->value, ...Role::values(Role::staff())]);
    }

    /**
     * Espace de l'application de ce compte : e-commerçant, livreur, ou équipe (administration et console).
     */
    public function space(): string
    {
        return match (true) {
            $this->isCourier() => 'courier',
            $this->merchant_id !== null => 'merchant',
            default => 'staff',
        };
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active
            && ($this->company === null || $this->company->isActive())
            && ($this->merchant_id === null || $this->merchant?->isActive() === true);
    }

    public function belongsToSameCompanyAs(self $other): bool
    {
        return $this->company_id !== null && $this->company_id === $other->company_id;
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /**
     * Comptes qui peuvent se connecter à cette adresse : ceux de l'entreprise de
     * l'adresse, ou le super administrateur sur la console (plateforme).
     */
    public function scopeLoginableHere(Builder $query): Builder
    {
        $tenancy = app(Tenancy::class);

        return match ($tenancy->zone()) {
            Tenancy::COMPANY => $query->where('company_id', $tenancy->company()?->id ?? 0),
            Tenancy::CONSOLE => $query->whereNull('company_id'),
            Tenancy::PLATFORM => $query->whereRaw('1 = 0'),
            default => $query,
        };
    }

    /**
     * Règle « déjà utilisé » propre à une entreprise (null : super administrateurs).
     */
    public static function uniqueIn(?int $companyId, string $column): Unique
    {
        return Rule::unique('users', $column)->where(fn ($q) => $companyId === null ? $q->whereNull('company_id') : $q->where('company_id', $companyId));
    }

    /**
     * Numéro ou e-mail déjà utilisé dans l'entreprise (comptes supprimés compris).
     */
    public static function takenIn(?int $companyId, string $column, ?string $value): bool
    {
        return filled($value) && static::query()->withoutGlobalScopes()->withTrashed()
            ->when($companyId === null, fn ($q) => $q->whereNull('company_id'), fn ($q) => $q->where('company_id', $companyId))
            ->where($column, $value)->exists();
    }
}
