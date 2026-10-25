<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Activity;
use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AccessRole;
use App\Models\User;
use App\Services\Access\Access;
use App\Services\Access\RoleTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rôles de l'équipe : rôles intégrés (modèles fixes) et rôles sur mesure de l'entreprise, catalogue des permissions
 * par domaine, modèles de départ.
 */
class RoleController extends Controller
{
    public function __construct(private readonly Access $access) {}

    /**
     * Rôles (avec ceux que le compte connecté peut attribuer), catalogue des permissions, activités et modèles.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->merchant_id === null && $actor->canAny([Permission::UsersView->value, Permission::UsersManage->value]), 403);

        $builtIn = Role::values([...Role::staff(), Role::Courier]);
        $roles = AccessRole::query()->with('permissions')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('is_custom', false)->whereIn('name', $builtIn))
                ->orWhere(fn ($q) => $q->customFor($actor->company_id)))
            ->get()
            ->sortBy(fn (AccessRole $r) => [$r->is_custom ? 1 : 0, $r->is_custom ? mb_strtolower((string) $r->label) : array_search($r->name, $builtIn, true)])
            ->values();
        // Comptes de l'entreprise seulement (les rôles intégrés sont partagés en base)
        $counts = User::query()->forCompany($actor->company_id)->with('roles')->get()
            ->groupBy(fn ($u) => $u->roleKey())->map->count();

        return response()->json([
            'data' => $roles->map(fn (AccessRole $r) => [...$this->access->summary($r, $actor), 'users_count' => $counts[$r->name] ?? 0])->values(),
            // Catalogue des permissions par domaine (écrans Rôles et Utilisateurs)
            'permissions' => array_map(fn (Permission $p) => [
                'value' => $p->value, 'label' => $p->label(), 'description' => $p->description(), 'domain' => $p->domain(),
            ], Permission::cases()),
            'domains' => collect(Permission::domains())->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'activities' => array_map(fn (Activity $a) => ['value' => $a->value, 'label' => $a->label()], Activity::cases()),
            'templates' => RoleTemplates::all(),
            // Droits du compte connecté : ce qu'il peut donner
            'grantable' => $this->access->isAdmin($actor) ? array_map(fn (Permission $p) => $p->value, Permission::cases()) : $actor->effectivePermissions(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permission::UsersManage->value), 403);
        $role = $this->access->saveCustom($request->user(), $this->validated($request));

        return response()->json(['data' => $this->access->summary($role, $request->user())], 201);
    }

    public function update(Request $request, AccessRole $role): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can(Permission::UsersManage->value) && $role->is_custom && $role->company_id === $actor->company_id, 403,
            'Les rôles intégrés ne se modifient pas : créez un rôle sur mesure à partir de celui-ci.');
        $role = $this->access->saveCustom($actor, $this->validated($request, $role), $role->load('permissions'));

        return response()->json(['data' => $this->access->summary($role, $actor)]);
    }

    public function destroy(Request $request, AccessRole $role): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can(Permission::UsersManage->value) && $role->is_custom && $role->company_id === $actor->company_id, 403);
        $this->access->assertCanGrant($actor, $role->permissions->pluck('name')->all(), 'role');
        $count = User::query()->role($role->name)->count();
        abort_if($count > 0, 422, "{$count} compte(s) ont ce rôle : donnez-leur un autre rôle avant de le supprimer.");
        $role->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?AccessRole $role = null): array
    {
        $companyId = $request->user()->company_id;
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80',
                Rule::unique('roles', 'label')->where('company_id', $companyId)->where('is_custom', true)->ignore($role?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'template' => ['nullable', 'string', 'max:40'],
            'permissions' => ['present', 'array'],
            'permissions.*' => [Rule::enum(Permission::class)],
            'activities' => ['nullable', 'array'],
            'activities.*' => [Rule::enum(Activity::class)],
            'zone_ids' => ['nullable', 'array'],
            'zone_ids.*' => ['integer', Rule::exists('zones', 'id')->where('company_id', $companyId)],
            'hub_ids' => ['nullable', 'array'],
            'hub_ids.*' => ['integer', Rule::exists('hubs', 'id')->where('company_id', $companyId)],
        ], [], ['label' => 'nom du rôle', 'permissions' => 'droits']);
        $data['permissions'] = array_values(array_unique($data['permissions']));

        return $data;
    }
}
