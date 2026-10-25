<?php

namespace App\Http\Requests\V1;

use App\Enums\Activity;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Services\Access\Access;
use App\Support\PhoneNumber as Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        if (! $this->user()->can('update', $user)) {
            return false;
        }

        // Changer un rôle ou un statut demande un droit supplémentaire
        // (personne ne peut modifier son propre rôle ou statut)
        if ($this->hasAny(['role', 'status', 'activities', 'extra_permissions', 'denied_permissions', 'zone_ids', 'hub_ids'])) {
            return ! $this->user()->is($user)
                && $this->user()->can('changeRoleOrStatus', $user);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => Phone::normalize($this->input('phone')) ?? $this->input('phone')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', new PhoneNumber, User::uniqueIn($user->company_id, 'phone')->ignore($user)],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', User::uniqueIn($user->company_id, 'email')->ignore($user)],
            'password' => ['sometimes', 'required', 'string', Password::min(8)],
            // Équipe : périmètre (activités visibles, vide : toutes) et réglage fin des permissions
            'activities' => ['sometimes', 'nullable', 'array'],
            'activities.*' => [Rule::enum(Activity::class)],
            'extra_permissions' => ['sometimes', 'array'],
            'extra_permissions.*' => [Rule::enum(Permission::class)],
            'denied_permissions' => ['sometimes', 'array'],
            'denied_permissions.*' => [Rule::enum(Permission::class)],
            'zone_ids' => ['sometimes', 'nullable', 'array'],
            'zone_ids.*' => ['integer', Rule::exists('zones', 'id')->where('company_id', $user->company_id)],
            'hub_ids' => ['sometimes', 'nullable', 'array'],
            'hub_ids.*' => ['integer', Rule::exists('hubs', 'id')->where('company_id', $user->company_id)],
            'role' => ['sometimes', 'required', Rule::in(app(Access::class)->assignableRoles($this->user())->pluck('name')->all())],
            'status' => ['sometimes', 'required', new Enum(UserStatus::class)],
        ];
    }

    public function after(): array
    {
        $access = app(Access::class);
        $user = $this->route('user');

        return [function ($validator) use ($access, $user) {
            if ($this->has('extra_permissions')) {
                // Seules les permissions nouvellement ajoutées comptent : celles déjà là restent
                $added = array_diff((array) $this->input('extra_permissions', []), $user->getDirectPermissions()->pluck('name')->all());
                $access->assertCanGrant($this->user(), array_values($added), 'extra_permissions');
            }
            // Toujours au moins un administrateur actif
            $leavesAdmin = ($this->filled('role') && $this->input('role') !== Role::Admin->value)
                || ($this->filled('status') && $this->input('status') !== UserStatus::Active->value);
            if ($leavesAdmin && $access->isLastAdmin($user)) {
                $validator->errors()->add('role', 'C\'est le dernier administrateur actif : nommez-en un autre d\'abord.');
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nom',
            'phone' => 'téléphone',
            'role' => 'rôle',
            'status' => 'statut',
            'password' => 'mot de passe',
        ];
    }
}
