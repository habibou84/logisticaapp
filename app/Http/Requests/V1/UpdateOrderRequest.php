<?php

namespace App\Http\Requests\V1;

use App\Enums\FeePayer;
use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('order'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $zone = Rule::exists('zones', 'id')->where('company_id', $this->user()->company_id)->where('is_active', true);

        return [
            'merchant_reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'pickup_zone_id' => ['sometimes', 'required', 'integer', $zone],
            'pickup_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'pickup_landmark' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pickup_contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pickup_phone' => ['sometimes', 'nullable', 'string', new PhoneNumber],
            'pickup_lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'recipient_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'recipient_phone' => ['sometimes', 'required', 'string', new PhoneNumber],
            'recipient_phone2' => ['sometimes', 'nullable', 'string', new PhoneNumber],
            'delivery_zone_id' => ['sometimes', 'required', 'integer', $zone],
            'delivery_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'delivery_landmark' => ['sometimes', 'nullable', 'string', 'max:255'],
            'delivery_lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'delivery_lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'delivery_scheduled_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'delivery_time_slot' => ['sometimes', 'nullable', 'string', 'max:20'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'package_size' => ['sometimes', 'nullable', Rule::in(['S', 'M', 'L', 'XL'])],
            'weight_kg' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:500'],
            'is_fragile' => ['sometimes', 'boolean'],
            'is_express' => ['sometimes', 'boolean'],
            'merchant_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'items_amount' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'fee_payer' => ['sometimes', Rule::enum(FeePayer::class)],
        ];
    }

    public function attributes(): array
    {
        return (new StoreOrderRequest)->attributes();
    }
}
