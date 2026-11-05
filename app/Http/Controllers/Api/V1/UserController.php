<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Activity;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreUserRequest;
use App\Http\Requests\V1\UpdateUserRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\Hub;
use App\Models\User;
use App\Models\UserAccountEvent;
use App\Models\Zone;
use App\Services\Assignment\MadCouriers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $actor = $request->user();

        $request->validate([
            // Onglets de la page Utilisateurs
            'scope' => ['nullable', Rule::in(self::SCOPES)],
            'activity' => ['nullable', Rule::enum(Activity::class)],
            'role' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->with(['roles', 'permissions', 'company', 'merchantBrief', 'courier:id,user_id', 'departments:id,name'])
            ->forCompany($actor->company_id)
            ->when($request->filled('scope'), fn ($q) => self::scope($q, $request->string('scope')->toString()))
            // Équipe d'une activité : comptes limités à elle, et ceux qui voient tout
            ->when($request->filled('activity'), fn ($q) => $q->where(fn ($q) => $q->whereJsonContains('activities', $request->string('activity')->toString())
                ->orWhereNull('activities')->orWhereJsonLength('activities', 0)))
            ->when($request->filled('role'), fn ($q) => $q->role($request->string('role')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.mb_strtolower($request->string('search')).'%';
                $q->where(fn ($q) => $q
                    ->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return UserResource::collection($users);
    }

    /** Onglets de la page Utilisateurs */
    public const SCOPES = ['team', 'couriers', 'merchants', 'suspended', 'inactive'];

    /** Sans connexion depuis ce nombre de jours : compte « à vérifier » */
    public const INACTIVE_DAYS = 30;

    /**
     * Comptes d'un onglet : équipe (ni livreur ni client), livreurs, comptes clients, suspendus, à vérifier (actifs,
     * jamais connectés ou sans connexion depuis INACTIVE_DAYS jours).
     */
    public static function scope(Builder $query, string $scope): Builder
    {
        return match ($scope) {
            'team' => $query->whereNull('merchant_id')->whereDoesntHave('roles', fn ($q) => $q->where('name', Role::Courier->value)),
            'couriers' => $query->whereHas('roles', fn ($q) => $q->where('name', Role::Courier->value)),
            'merchants' => $query->whereNotNull('merchant_id'),
            'suspended' => $query->where('status', UserStatus::Suspended->value),
            'inactive' => $query->where('status', UserStatus::Active->value)
                ->where(fn ($q) => $q->whereNull('last_login_at')->orWhere('last_login_at', '<', now()->subDays(self::INACTIVE_DAYS))),
        };
    }

    /** Compteurs de la page : par onglet, par rôle et par état */
    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);
        $base = fn () => User::query()->forCompany($request->user()->company_id);
        $count = fn (string $scope) => self::scope($base(), $scope)->count();
        $roles = $base()->with('roles:id,name')->get(['id', 'status', 'merchant_id', 'last_login_at'])
            ->groupBy(fn (User $u) => $u->roles->first()?->name ?? 'none')
            ->map(fn ($list) => ['total' => $list->count(), 'active' => $list->where('status', UserStatus::Active)->count(), 'suspended' => $list->where('status', UserStatus::Suspended)->count()]);
        $active = fn () => $base()->where('status', UserStatus::Active->value);

        return response()->json(['data' => [
            ...collect(self::SCOPES)->mapWithKeys(fn ($s) => [$s => $count($s)])->all(),
            'roles' => $roles,
            'never_logged' => $active()->whereNull('last_login_at')->count(),
            'inactive_old' => $active()->whereNotNull('last_login_at')->where('last_login_at', '<', now()->subDays(self::INACTIVE_DAYS))->count(),
            'mad_couriers' => count(app(MadCouriers::class)->reservations($request->user()->company_id)),
            'inactive_days' => self::INACTIVE_DAYS,
        ]]);
    }

    /** Déconnecter le compte de tous ses appareils */
    public function logout(Request $request, User $user): JsonResponse
    {
        Gate::authorize('update', $user);
        abort_if($request->user()->is($user), 422, 'Pour vous déconnecter, utilisez le bouton Déconnexion.');
        $user->tokens()->delete();
        UserAccountEvent::record($user, $request->user(), UserAccountEvent::LOGGED_OUT);

        return response()->json(['data' => ['logged_out' => true]]);
    }

    /** Historique du compte (le plus récent d'abord) */
    public function history(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return response()->json(['data' => UserAccountEvent::query()->where('user_id', $user->id)->with('actor:id,name')
            ->orderByDesc('id')->limit(200)->get()
            ->map(fn (UserAccountEvent $e) => ['id' => $e->id, 'at' => $e->created_at, 'action' => $e->action, 'text' => $e->text(), 'by' => $e->actor?->name])->values()]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $actor = $request->user();
        $data = $request->validated();

        $user = DB::transaction(function () use ($actor, $data) {
            $user = User::create([
                'company_id' => $actor->company_id,
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                // Sans mot de passe : la personne se connecte par le lien envoyé sur WhatsApp
                'password' => $data['password'] ?? Str::random(40),
                'activities' => $data['activities'] ?? null,
                'denied_permissions' => $data['denied_permissions'] ?? null,
                'zone_ids' => $data['zone_ids'] ?? null,
                'hub_ids' => $data['hub_ids'] ?? null,
            ]);

            $user->assignRole($data['role']);
            $user->syncPermissions($data['extra_permissions'] ?? []);
            $user->syncCourierProfile();
            UserAccountEvent::record($user, $actor, UserAccountEvent::CREATED, ['role' => $user->accessRole()?->displayLabel()]);

            return $user;
        });

        return UserResource::make($user->load(['roles', 'permissions', 'company']))
            ->response()
            ->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return UserResource::make($user->load(['roles', 'permissions', 'company']));
    }

    /**
     * « Voir comme ce compte » : ses droits effectifs (rôle, ajoutés, retirés) et son périmètre, pour l'aperçu de ce
     * qu'il verra.
     */
    public function access(Request $request, User $user): JsonResponse
    {
        Gate::authorize('view', $user);
        // « Voir comme ce compte » : gardé dans l'historique du compte regardé
        if (! $request->user()->is($user)) {
            UserAccountEvent::record($user, $request->user(), UserAccountEvent::SEEN_AS);
        }
        $user->load(['roles.permissions', 'permissions']);
        $role = $user->accessRole();
        $zones = Zone::query()->whereIn('id', $user->zoneScope() ?? [])->whereNull('parent_id')->orderBy('name')->pluck('name');
        $hubs = Hub::query()->whereIn('id', $user->hubScope() ?? [])->orderBy('name')->pluck('name');

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $role?->name,
            'role_label' => $role?->displayLabel(),
            'space' => $user->space(),
            'permissions' => $user->effectivePermissions(),
            'from_role' => $role?->permissions->pluck('name')->values() ?? [],
            'extra_permissions' => $user->permissions->pluck('name')->values(),
            'denied_permissions' => $user->denied_permissions ?? [],
            'scope' => [
                'activities' => $user->activityScope() ?? [],
                'zones' => $zones,
                'hubs' => $hubs,
                // D'où vient le périmètre : le compte lui-même ou son rôle
                'from_role' => ! $user->activities && ! $user->zone_ids && ! $user->hub_ids,
            ],
        ]]);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $data = $request->validated();

        $actor = $request->user();
        $before = [
            'name' => $user->name,
            'role' => $user->accessRole()?->displayLabel(),
            'perimeter' => UserAccountEvent::perimeter($user->activities, $user->zone_ids, $user->hub_ids),
            'rights' => [...$user->permissions()->pluck('name')->map(fn ($p) => "+{$p}")->all(), ...array_map(fn ($p) => "-{$p}", $user->denied_permissions ?? [])],
        ];

        DB::transaction(function () use ($user, $data) {
            $user->fill(collect($data)->except(['role', 'extra_permissions'])->all())->save();

            // Réglage fin : permissions ajoutées au rôle (directes) ; celles retirées sont dans denied_permissions
            if (array_key_exists('extra_permissions', $data)) {
                $user->syncPermissions($data['extra_permissions']);
            }

            if (isset($data['role'])) {
                $user->syncRoles([$data['role']]);
                $user->syncCourierProfile();
            }

            // Un compte suspendu ou dont le mot de passe change est déconnecté partout
            if ($user->wasChanged(['status', 'password'])) {
                $user->tokens()->delete();
            }
        });

        $this->recordChanges($user->unsetRelation('roles')->unsetRelation('permissions'), $actor, $before, $data);

        return UserResource::make($user->load(['roles', 'permissions', 'company']));
    }

    /**
     * Historique d'une modification : nom, accès, statut, rôle, périmètre, droits.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $data
     */
    private function recordChanges(User $user, User $actor, array $before, array $data): void
    {
        $label = fn (string $p) => Permission::tryFrom($p)?->label() ?? $p;
        if ($before['name'] !== $user->name) {
            UserAccountEvent::record($user, $actor, UserAccountEvent::RENAMED, ['from' => $before['name'], 'to' => $user->name]);
        }
        $fields = array_values(array_filter(['phone', 'email', 'password'], fn ($f) => array_key_exists($f, $data) && $user->wasChanged($f)));
        if ($fields !== []) {
            UserAccountEvent::record($user, $actor, UserAccountEvent::CREDENTIALS_CHANGED, ['fields' => $fields]);
        }
        if ($user->wasChanged('status')) {
            UserAccountEvent::record($user, $actor, $user->status === UserStatus::Suspended ? UserAccountEvent::SUSPENDED : UserAccountEvent::REACTIVATED);
        }
        $role = $user->accessRole()?->displayLabel();
        if ($role !== $before['role']) {
            UserAccountEvent::record($user, $actor, UserAccountEvent::ROLE_CHANGED, ['from' => $before['role'], 'to' => $role]);
        }
        $perimeter = UserAccountEvent::perimeter($user->activities, $user->zone_ids, $user->hub_ids);
        if ($perimeter !== $before['perimeter']) {
            UserAccountEvent::record($user, $actor, UserAccountEvent::PERIMETER_CHANGED, ['from' => $before['perimeter'], 'to' => $perimeter]);
        }
        $rights = [...$user->permissions()->pluck('name')->map(fn ($p) => "+{$p}")->all(), ...array_map(fn ($p) => "-{$p}", $user->denied_permissions ?? [])];
        $added = array_values(array_diff($rights, $before['rights']));
        $removed = array_values(array_diff($before['rights'], $rights));
        if ($added !== [] || $removed !== []) {
            // « +droit » ajouté au rôle, « -droit » retiré du rôle : en clair dans l'historique
            $describe = fn (string $r) => ($r[0] === '+' ? 'ajout de ' : 'retrait de ').$label(substr($r, 1));
            UserAccountEvent::record($user, $actor, UserAccountEvent::RIGHTS_CHANGED, ['added' => array_map($describe, $added), 'removed' => array_map(fn ($r) => 'fin de « '.$describe($r).' »', $removed)]);
        }
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($request->user()->is($user), 403, 'Vous ne pouvez pas supprimer votre propre compte.');
        Gate::authorize('delete', $user);

        $user->tokens()->delete();
        $user->delete();

        return response()->json(null, 204);
    }
}
