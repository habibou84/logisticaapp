<?php

namespace App\Http\Requests\V1;

use App\Enums\Activity;
use App\Enums\FeePayer;
use App\Enums\MerchantStatus;
use App\Rules\PhoneNumber;
use App\Support\PhoneNumber as Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('merchant'));
    }

    protected function prepareForValidation(): void
    {
        foreach (['phone', 'whatsapp_phone'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => Phone::normalize($this->input($key)) ?? $this->input($key)]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->user()->company_id ?? $this->route('merchant')->company_id;

        return [
            // Activités du client (au moins une), dans le périmètre de l'utilisateur
            'activities' => ['sometimes', 'array', 'min:1'],
            'activities.*' => [Rule::enum(Activity::class), function ($attribute, $value, $fail) {
                if (! $this->user()->seesActivity((string) $value)) {
                    $fail('Vous ne gérez pas cette activité.');
                }
            }],
            'business_name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', new PhoneNumber,
                Rule::unique('merchants', 'phone')->where('company_id', $companyId)->ignore($this->route('merchant'))],
            'whatsapp_phone' => ['sometimes', 'nullable', 'string', new PhoneNumber],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'pickup_zone_id' => ['sometimes', 'nullable', 'integer', Rule::exists('zones', 'id')->where('company_id', $companyId)],
            'pickup_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'pickup_landmark' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pickup_lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'pricing_grid_id' => ['sometimes', 'nullable', 'integer', Rule::exists('pricing_grids', 'id')->where('company_id', $companyId)],
            'default_fee_payer' => ['sometimes', Rule::enum(FeePayer::class)],
            'status' => ['sometimes', Rule::enum(MerchantStatus::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Identité fiscale (factures) et exonération de TVA
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ncc' => ['sometimes', 'nullable', 'string', 'max:30'],
            'rccm' => ['sometimes', 'nullable', 'string', 'max:60'],
            'billing_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'vat_exempt' => ['sometimes', 'boolean'],
            'vat_exemption_ref' => ['sometimes', 'nullable', 'string', 'max:120'],
            'vat_exemption_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'vat_exemption_type' => ['sometimes', 'in:conventional,legal'],
            'fne_template' => ['sometimes', 'nullable', 'in:B2B,B2C,B2G'],
        ];
    }

    public function attributes(): array
    {
        return (new StoreMerchantRequest)->attributes();
    }
}
