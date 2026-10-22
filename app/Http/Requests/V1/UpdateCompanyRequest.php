<?php

namespace App\Http\Requests\V1;

use App\Rules\PhoneNumber;
use App\Support\PhoneNumber as Phone;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('company'));
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
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'timezone' => ['sometimes', 'required', 'timezone:all'],
            'phone' => ['sometimes', 'nullable', 'string', new PhoneNumber],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'auto_confirm_orders' => ['sometimes', 'boolean'],
            'validation_mode' => ['sometimes', 'in:manual,auto,rules'],
            'default_max_attempts' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'require_delivery_code' => ['sometimes', 'boolean'],
            'offline_courier_actions' => ['sometimes', 'boolean'],
            'return_fee_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'field_alert_reminder_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'parcel_hold_alert_hours' => ['sometimes', 'integer', 'min:0', 'max:168'],
            'pickup_mission_custody' => ['sometimes', 'in:keep,depot,choose'],
            'depot_closes_at' => ['sometimes', 'nullable', 'date_format:H:i'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'merchant_signup' => ['sometimes', 'boolean'],
            'weekly_report' => ['sometimes', 'boolean'],
            'pickup_assign_alert_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            // Heure limite du jour (HH:MM, heure de l'entreprise) ; vide = aucune
            'daily_cutoff_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'delivery_assign_alert_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            // Ramassages confiés selon le planning : off, trial (essai), on
            'auto_pickup' => ['sometimes', 'in:off,trial,on'],
            'mission_acceptance' => ['sometimes', 'in:choose,report,auto'],
            'auto_delivery' => ['sometimes', 'in:off,trial,on'],
            'auto_delivery_max_cod' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'same_courier_delivery' => ['sometimes', 'boolean'],
            'cutoff_by_day' => ['sometimes', 'nullable', 'array'],
            'cutoff_by_day.*' => ['nullable', 'date_format:H:i'],
            'unreachable_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'late_pickup_alert_minutes' => ['sometimes', 'integer', 'min:0', 'max:600'],
            'courier_available_alert' => ['sometimes', 'boolean'],
            'directory_listed' => ['sometimes', 'boolean'],
            'directory_pitch' => ['sometimes', 'nullable', 'string', 'max:240'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nom',
            'phone' => 'téléphone',
            'address' => 'adresse',
            'daily_cutoff_time' => 'heure limite du jour',
            'pickup_mission_custody' => 'colis après une mission ramassage',
            'depot_closes_at' => 'heure de fermeture du dépôt',
            'auto_pickup' => 'ramassage automatique',
            'mission_acceptance' => 'acceptation des missions',
            'auto_delivery' => 'livraison automatique',
            'auto_delivery_max_cod' => 'plafond du montant à encaisser',
            'same_courier_delivery' => 'même livreur du ramassage à la livraison',
            'cutoff_by_day' => 'heure limite par jour',
            'unreachable_minutes' => 'délai livreur injoignable',
            'late_pickup_alert_minutes' => 'alerte ramassage pas commencé',
            'courier_available_alert' => 'alerte livreur libre',
            'directory_listed' => 'annuaire',
            'directory_pitch' => 'présentation dans l\'annuaire',
        ];
    }
}
