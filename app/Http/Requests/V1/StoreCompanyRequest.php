<?php

namespace App\Http\Requests\V1;

use App\Models\Company;
use App\Rules\PhoneNumber;
use App\Support\PhoneNumber as Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Company::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => Phone::normalize($this->input('phone')) ?? $this->input('phone')]);
        }

        $admin = $this->input('admin');

        if (is_array($admin) && is_string($admin['phone'] ?? null)) {
            $admin['phone'] = Phone::normalize($admin['phone']) ?? $admin['phone'];
            $this->merge(['admin' => $admin]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Adresse : {slug}.jibiat.com (proposée d'après le nom si vide)
            'slug' => ['nullable', ...Company::slugRules()],
            'timezone' => ['nullable', 'timezone:all'],
            'phone' => ['nullable', 'string', new PhoneNumber],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'auto_confirm_orders' => ['boolean'],
            'default_max_attempts' => ['integer', 'min:1', 'max:10'],
            // Formule proposée (active) ; essai gratuit par défaut (PLATFORM_TRIAL_DAYS)
            'plan_id' => ['nullable', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
            'trial_ends_at' => ['nullable', 'date_format:Y-m-d'],

            // Premier administrateur de l'entreprise (facultatif)
            'admin' => ['nullable', 'array'],
            'admin.name' => ['required_with:admin', 'string', 'max:255'],
            // Nouvelle entreprise : aucun compte encore, le numéro peut servir ailleurs
            'admin.phone' => ['required_with:admin', 'string', new PhoneNumber],
            'admin.email' => ['nullable', 'email', 'max:255'],
            'admin.password' => ['required_with:admin', 'string', Password::min(8)],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nom',
            'phone' => 'téléphone',
            'address' => 'adresse',
            'plan_id' => 'formule',
            'trial_ends_at' => 'fin de l\'essai',
            'admin.name' => 'nom de l\'administrateur',
            'admin.phone' => 'téléphone de l\'administrateur',
            'admin.email' => 'e-mail de l\'administrateur',
            'admin.password' => 'mot de passe de l\'administrateur',
        ];
    }
}
