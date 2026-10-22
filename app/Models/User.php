<?php

namespace App\Models;

use App\Enums\Activity;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Support\PhoneNumber;
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
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    use HasRoles {
        hasPermissionTo as private roleHasPermissionTo;
    }

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
        // Périmètre de l'équipe (activités visibles, vide : toutes) et permissions retirées au rôle
        'activities',
        'denied_permissions',
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
            'activities' => 'array',
            'denied_permissions' => 'array',
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

    /**
     * Activités que ce compte de l'équipe voit (valeurs), ou null : toutes (administrateur, compte sans
     * périmètre, livreur, client).
     *
     * @return list<string>|null
     */
    public function activityScope(): ?array
    {
        if ($this->merchant_id !== null || $this->isCourier() || $this->hasRole(Role::Admin->value)) {
            return null;
        }
        $values = array_values(array_intersect(Activity::values(), (array) ($this->activities ?? [])));

        return $values === [] ? null : $values;
    }

    /**
     * Réglage fin : une permission retirée à ce compte l'emporte sur son rôle et ses permissions ajoutées
     * (vérifications par can(), les middlewares et les policies).
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        $name = $permission instanceof \BackedEnum ? $permission->value : (is_string($permission) ? $permission : ($permission->name ?? null));
        if ($name !== null && in_array($name, (array) ($this->denied_permissions ?? []), true)) {
            return false;
        }

        return $this->roleHasPermissionTo($permission, $guardName);
    }

    /**
     * Permissions effectives : celles du rôle et celles ajoutées, moins celles retirées (réglage fin).
     *
     * @return list<string>
     */
    public function effectivePermissions(): array
    {
        return $this->getAllPermissions()->pluck('name')
            ->diff((array) ($this->denied_permissions ?? []))->values()->all();
    }

    public function seesActivity(Activity|string $activity): bool
    {
        $scope = $this->activityScope();

        return $scope === null || in_array($activity instanceof Activity ? $activity->value : $activity, $scope, true);
    }

    public function isCourier(): bool
    {
        return $this->hasRole(Role::Courier->value);
    }

    /**
     * Personnel de l'entreprise de livraison (back-office).
     */
    public function isStaff(): bool
    {
        return $this->hasAnyRole(Role::values(Role::staff()));
    }

    /**
     * Espace de l'application de ce compte : client, livreur, ou équipe (administration).
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
