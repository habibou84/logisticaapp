<?php

namespace App\Http\Requests\V1;

use App\Enums\FeePayer;
use App\Models\Merchant;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Support\PhoneNumber as Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreMerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Merchant::class);
    }

    protected function prepareForValidation(): void
    {
        foreach (['phone', 'whatsapp_phone'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => Phone::normalize($this->input($key)) ?? $this->input($key)]);
            }
        }

        $owner = $this->input('owner');
        if (is_array($owner) && is_string($owner['phone'] ?? null)) {
            $owner['phone'] = Phone::normalize($owner['phone']) ?? $owner['phone'];
            $this->merge(['owner' => $owner]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'business_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', new PhoneNumber, Rule::unique('merchants', 'phone')->where('company_id', $companyId)],
            'whatsapp_phone' => ['nullable', 'string', new PhoneNumber],
            'email' => ['nullable', 'email', 'max:255'],
            'pickup_zone_id' => ['nullable', 'integer', Rule::exists('zones', 'id')->where('company_id', $companyId)],
            'pickup_address' => ['nullable', 'string', 'max:500'],
            'pickup_landmark' => ['nullable', 'string', 'max:255'],
            'pickup_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'pricing_grid_id' => ['nullable', 'integer', Rule::exists('pricing_grids', 'id')->where('company_id', $companyId)],
            'default_fee_payer' => ['nullable', Rule::enum(FeePayer::class)],
            'notes' => ['nullable', 'string', 'max:2000'],

            // Compte de connexion du marchand (facultatif)
            'owner' => ['nullable', 'array'],
            'owner.name' => ['required_with:owner', 'string', 'max:255'],
            'owner.phone' => ['required_with:owner', 'string', new PhoneNumber, User::uniqueIn($this->user()->company_id, 'phone')],
            'owner.email' => ['nullable', 'email', 'max:255', User::uniqueIn($this->user()->company_id, 'email')],
            'owner.password' => ['required_with:owner', 'string', Password::min(8)],
        ];
    }

    public function attributes(): array
    {
        return [
            'business_name' => 'nom commercial',
            'phone' => 'téléphone',
            'whatsapp_phone' => 'numéro WhatsApp',
            'pickup_zone_id' => 'zone de ramassage',
            'pricing_grid_id' => 'grille tarifaire',
            'owner.name' => 'nom du gérant',
            'owner.phone' => 'téléphone du gérant',
            'owner.email' => 'e-mail du gérant',
            'owner.password' => 'mot de passe du gérant',
        ];
    }
}
