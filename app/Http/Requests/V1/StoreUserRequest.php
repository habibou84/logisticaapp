<?php

namespace App\Http\Requests\V1;

use App\Enums\Activity;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Rules\PhoneNumber;
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
            'role' => ['required', Rule::in(Role::values(Role::assignableBy($actor)))],
        ];
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
