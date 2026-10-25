<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreUserRequest;
use App\Http\Requests\V1\UpdateUserRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\Hub;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $actor = $request->user();

        $request->validate([
            'role' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->with(['roles', 'permissions', 'company'])
            ->forCompany($actor->company_id)
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
                'password' => $data['password'],
                'activities' => $data['activities'] ?? null,
                'denied_permissions' => $data['denied_permissions'] ?? null,
                'zone_ids' => $data['zone_ids'] ?? null,
                'hub_ids' => $data['hub_ids'] ?? null,
            ]);

            $user->assignRole($data['role']);
            $user->syncPermissions($data['extra_permissions'] ?? []);
            $user->syncCourierProfile();

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
    public function access(User $user): JsonResponse
    {
        Gate::authorize('view', $user);
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

        return UserResource::make($user->load(['roles', 'permissions', 'company']));
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
