<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Numéro de téléphone ou adresse e-mail
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
            // Page de connexion utilisée : seuls les comptes de cet espace y entrent
            'space' => ['nullable', 'in:merchant,courier,staff'],
        ];
    }

    public function attributes(): array
    {
        return ['login' => 'identifiant'];
    }
}
