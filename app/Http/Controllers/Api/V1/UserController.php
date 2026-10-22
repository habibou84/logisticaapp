<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreUserRequest;
use App\Http\Requests\V1\UpdateUserRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
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
            'role' => ['nullable', Rule::in(Role::values(Role::cases()))],
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
