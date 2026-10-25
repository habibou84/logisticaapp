<?php

namespace App\Http\Requests\V1;

use App\Enums\Activity;
use App\Enums\Permission;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Services\Access\Access;
use App\Support\PhoneNumber as Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
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
        $actor = $this->user();
        $companyId = $actor->company_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            // Unique dans l'entreprise du compte (un même numéro peut servir dans une autre entreprise)
            'phone' => ['required', 'string', new PhoneNumber, User::uniqueIn($companyId, 'phone')],
            'email' => ['nullable', 'email', 'max:255', User::uniqueIn($companyId, 'email')],
            'password' => ['required', 'string', Password::min(8)],
            // Équipe : périmètre (activités visibles, vide : toutes) et réglage fin des permissions
            'activities' => ['sometimes', 'nullable', 'array'],
            'activities.*' => [Rule::enum(Activity::class)],
            'extra_permissions' => ['sometimes', 'array'],
            'extra_permissions.*' => [Rule::enum(Permission::class)],
            'denied_permissions' => ['sometimes', 'array'],
            'denied_permissions.*' => [Rule::enum(Permission::class)],
            // Périmètre par zones et dépôts (vide : celui du rôle)
            'zone_ids' => ['sometimes', 'nullable', 'array'],
            'zone_ids.*' => ['integer', Rule::exists('zones', 'id')->where('company_id', $companyId)],
            'hub_ids' => ['sometimes', 'nullable', 'array'],
            'hub_ids.*' => ['integer', Rule::exists('hubs', 'id')->where('company_id', $companyId)],
            'role' => ['required', Rule::in(app(Access::class)->assignableRoles($actor)->pluck('name')->all())],
        ];
    }

    public function after(): array
    {
        // Personne ne donne un droit qu'il n'a pas lui-même
        return [fn () => app(Access::class)->assertCanGrant($this->user(), (array) $this->input('extra_permissions', []), 'extra_permissions')];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nom',
            'phone' => 'téléphone',
            'role' => 'rôle',
            'password' => 'mot de passe',
        ];
    }
}
