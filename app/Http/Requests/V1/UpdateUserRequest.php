<?php

namespace App\Http\Requests\V1;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Rules\PhoneNumber;
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
        if ($this->hasAny(['role', 'status'])) {
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
            'role' => ['sometimes', 'required', Rule::in(Role::values(Role::assignableBy($this->user())))],
            'status' => ['sometimes', 'required', new Enum(UserStatus::class)],
        ];
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
