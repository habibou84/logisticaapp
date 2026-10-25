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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        // Périmètre par zones et dépôts (vide : celui du rôle, sinon tout)
        'zone_ids',
        'hub_ids',
    ];

    /** @var array<string, list<int>|null> périmètre calculé une fois par requête */
    private array $perimeterCache = [];

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
            'guide_videos_hidden_at' => 'datetime',
            'guide_videos_watched' => 'array',
            'password' => 'hashed',
            'activities' => 'array',
            'denied_permissions' => 'array',
            'zone_ids' => 'array',
            'hub_ids' => 'array',
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

    /** Départements du client auxquels ce compte est rattaché */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class)->withPivot('is_head');
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
     * Rôle intégré de l'utilisateur (un seul rôle par compte) ; null pour un rôle sur mesure.
     */
    public function primaryRole(): ?Role
    {
        $name = $this->getRoleNames()->first();

        return $name ? Role::tryFrom($name) : null;
    }

    /** Rôle du compte, intégré ou sur mesure */
    public function accessRole(): ?AccessRole
    {
        return $this->roles->first();
    }

    /** Clé du rôle (valeur du rôle intégré, ou nom technique du rôle sur mesure) */
    public function roleKey(): ?string
    {
        return $this->getRoleNames()->first();
    }

    public function roleLabel(): ?string
    {
        return $this->accessRole()?->displayLabel();
    }

    public function hasCustomRole(): bool
    {
        return (bool) $this->accessRole()?->is_custom;
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
        // Celui du compte, sinon celui de son rôle sur mesure
        $own = array_values(array_intersect(Activity::values(), (array) ($this->activities ?? [])));
        $values = $own !== [] ? $own : array_values(array_intersect(Activity::values(), (array) ($this->accessRole()?->activities ?? [])));

        return $values === [] ? null : $values;
    }

    /**
     * Zones des courses que ce compte de l'équipe voit (communes avec leurs quartiers), ou null : toutes.
     * Celles du compte, sinon celles de son rôle sur mesure.
     *
     * @return list<int>|null
     */
    public function zoneScope(): ?array
    {
        return $this->perimeter('zone_ids', function (array $ids) {
            $children = Zone::withoutGlobalScopes()->where('company_id', $this->company_id)->whereIn('parent_id', $ids)->pluck('id')->all();

            return array_values(array_unique(array_map('intval', [...$ids, ...$children])));
        });
    }

    /**
     * Dépôts des courses que ce compte de l'équipe voit (courses ramassées vers ces dépôts), ou null : tous.
     *
     * @return list<int>|null
     */
    public function hubScope(): ?array
    {
        return $this->perimeter('hub_ids', fn (array $ids) => array_values(array_map('intval', $ids)));
    }

    /**
     * @param  callable(list<int>): list<int>  $expand
     * @return list<int>|null
     */
    private function perimeter(string $column, callable $expand): ?array
    {
        if (array_key_exists($column, $this->perimeterCache)) {
            return $this->perimeterCache[$column];
        }
        $ids = [];
        if ($this->merchant_id === null && ! $this->isCourier() && ! $this->hasRole(Role::Admin->value)) {
            $ids = (array) ($this->{$column} ?: ($this->accessRole()?->{$column} ?? []));
        }

        return $this->perimeterCache[$column] = $ids === [] ? null : $expand($ids);
    }

    /** Périmètre recalculé (rôle ou compte modifié dans la même requête) */
    public function forgetPerimeter(): void
    {
        $this->perimeterCache = [];
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

    /** Propriétaire ou gestionnaire d'un client : voit tout le client, gère ses comptes, valide ses demandes */
    public function isMerchantLead(): bool
    {
        return $this->merchant_id !== null && $this->hasAnyRole([Role::MerchantOwner->value, Role::MerchantManager->value]);
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
        return $this->hasAnyRole(Role::values(Role::staff())) || $this->hasCustomRole();
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
