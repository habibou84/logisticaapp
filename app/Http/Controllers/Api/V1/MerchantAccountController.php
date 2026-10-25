<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Support\PhoneNumber as Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Comptes d'un client : propriétaire, gestionnaire, agent, lecteur. Gérés par l'équipe (droit « Gérer les clients »)
 * ou par le client lui-même (propriétaire : tous les rôles ; gestionnaire : tous sauf propriétaire).
 */
class MerchantAccountController extends Controller
{
    public function index(Request $request, Merchant $merchant): JsonResponse
    {
        $actor = $request->user();
        abort_unless($this->canView($actor, $merchant), 403);

        $users = User::query()->where('merchant_id', $merchant->id)->with(['roles', 'departments:id'])->orderBy('name')->get();

        return response()->json([
            'data' => $users->map(fn (User $u) => $this->row($u))->values(),
            'roles' => array_map(fn (Role $r) => ['value' => $r->value, 'label' => $r->label(), 'description' => $this->describe($r)], Role::merchant()),
            'assignable' => Role::values($this->canManage($actor, $merchant) ? Role::merchantAssignableBy($actor) : []),
        ]);
    }

    public function store(Request $request, Merchant $merchant): JsonResponse
    {
        $actor = $request->user();
        abort_unless($this->canManage($actor, $merchant), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', new PhoneNumber],
            'email' => ['nullable', 'email', 'max:255', User::uniqueIn($merchant->company_id, 'email')],
            'password' => ['required', 'string', Password::min(8)],
            'role' => ['required', Rule::in(Role::values(Role::merchantAssignableBy($actor)))],
        ], [], ['name' => 'nom', 'phone' => 'téléphone', 'role' => 'rôle', 'password' => 'mot de passe']);

        $phone = Phone::normalize($data['phone']);
        if (User::takenIn($merchant->company_id, 'phone', $phone)) {
            throw ValidationException::withMessages(['phone' => 'Ce téléphone est déjà utilisé.']);
        }

        $user = DB::transaction(function () use ($data, $merchant) {
            $user = User::create([...Arr::except($data, 'role'), 'company_id' => $merchant->company_id, 'merchant_id' => $merchant->id]);
            $user->assignRole($data['role']);

            return $user;
        });

        return response()->json(['data' => $this->row($user->load(['roles', 'departments:id']))], 201);
    }

    public function update(Request $request, Merchant $merchant, User $user): JsonResponse
    {
        $actor = $request->user();
        abort_unless($user->merchant_id === $merchant->id && $this->canManage($actor, $merchant), 404);
        $assignable = Role::values(Role::merchantAssignableBy($actor));
        // Jamais un compte plus puissant que soi (le gestionnaire ne touche pas au propriétaire)
        abort_unless(in_array($user->roleKey(), $assignable, true), 403, 'Vous ne pouvez pas modifier ce compte.');

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', User::uniqueIn($merchant->company_id, 'email')->ignore($user)],
            'password' => ['sometimes', 'nullable', 'string', Password::min(8)],
            'role' => ['sometimes', 'required', Rule::in($assignable)],
            'status' => ['sometimes', 'required', Rule::enum(UserStatus::class)],
        ], [], ['name' => 'nom', 'role' => 'rôle', 'status' => 'statut', 'password' => 'mot de passe']);

        if ($actor->is($user) && (isset($data['role']) || isset($data['status']))) {
            throw ValidationException::withMessages(['role' => 'Vous ne pouvez pas changer votre propre rôle ou statut.']);
        }
        $leavesOwner = (isset($data['role']) && $data['role'] !== Role::MerchantOwner->value)
            || (isset($data['status']) && $data['status'] !== UserStatus::Active->value);
        if ($leavesOwner && $this->isLastOwner($user)) {
            throw ValidationException::withMessages(['role' => 'C\'est le dernier propriétaire actif de ce client : nommez-en un autre d\'abord.']);
        }

        DB::transaction(function () use ($user, $data) {
            $user->fill(Arr::except(array_filter($data, fn ($v, $k) => $k !== 'password' || filled($v), ARRAY_FILTER_USE_BOTH), 'role'))->save();
            if (isset($data['role'])) {
                $user->syncRoles([$data['role']]);
            }
            if ($user->wasChanged(['status', 'password'])) {
                $user->tokens()->delete();
            }
        });

        return response()->json(['data' => $this->row($user->fresh(['roles', 'departments:id']))]);
    }

    private function canView(User $actor, Merchant $merchant): bool
    {
        return $actor->merchant_id === null
            ? $actor->company_id === $merchant->company_id && $actor->canAny([Permission::MerchantsView->value, Permission::MerchantsManage->value])
            : $actor->merchant_id === $merchant->id && $actor->isMerchantLead();
    }

    private function canManage(User $actor, Merchant $merchant): bool
    {
        return $actor->merchant_id === null
            ? $actor->company_id === $merchant->company_id && $actor->can(Permission::MerchantsManage->value)
            : $actor->merchant_id === $merchant->id && $actor->isMerchantLead();
    }

    private function isLastOwner(User $user): bool
    {
        return $user->hasRole(Role::MerchantOwner->value) && $user->status === UserStatus::Active
            && User::query()->where('merchant_id', $user->merchant_id)->where('id', '!=', $user->id)
                ->where('status', UserStatus::Active->value)->role(Role::MerchantOwner->value)->doesntExist();
    }

    /** @return array<string, mixed> */
    private function row(User $u): array
    {
        return [
            'id' => $u->id, 'name' => $u->name, 'phone' => $u->phone, 'email' => $u->email,
            'role' => $u->roleKey(), 'role_label' => $u->roleLabel(), 'status' => $u->status,
            'last_login_at' => $u->last_login_at, 'department_ids' => $u->departments->modelKeys(),
        ];
    }

    private function describe(Role $role): string
    {
        return match ($role) {
            Role::MerchantOwner => 'Tout : courses, caisse, factures, comptes, clés d\'API.',
            Role::MerchantManager => 'Courses, caisse, factures et comptes (sauf le propriétaire) ; voit tous les départements et valide leurs demandes.',
            Role::MerchantStaff => 'Crée et suit les courses (de ses départements s\'il y en a).',
            Role::MerchantViewer => 'Suit les courses, sans rien créer ni annuler.',
            default => '',
        };
    }
}
