<?php

namespace App\Http\Requests\V1;

use App\Rules\PhoneNumber;
use App\Services\Orders\ActivityOptions;
use App\Support\InvoicePayments;
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
            'invoice_payment_info' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // Moyens de paiement des factures : comptes bancaires, Mobile Money, chèque… au choix de l'entreprise
            ...InvoicePayments::rules(),
            // Délai interne de distribution par activité, en heures (vide : aucun)
            'internal_delays' => ['sometimes', 'nullable', 'array:ecommerce,distribution,mad'],
            'internal_delays.*' => ['nullable', 'integer', 'min:1', 'max:2000'],
            // Distribution et MAD : preuve de remise, messages aux destinataires, réservation des livreurs en MAD
            'activity_options' => ['sometimes', 'nullable', 'array:distribution,mad'],
            ...ActivityOptions::rules('activity_options.distribution'),
            ...ActivityOptions::rules('activity_options.mad'),
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

    public function messages(): array
    {
        return [
            'invoice_payment_methods.*.bank.required_if' => 'Indiquez la banque du compte.',
            'invoice_payment_methods.*.iban.required_if' => 'Indiquez l\'IBAN du compte bancaire.',
            'invoice_payment_methods.*.operator.required_if' => 'Indiquez l\'opérateur Mobile Money (Orange Money, Wave…).',
            'invoice_payment_methods.*.phone.required_if' => 'Indiquez le numéro Mobile Money.',
            'invoice_payment_methods.*.payee.required_if' => 'Indiquez à l\'ordre de qui les chèques sont faits.',
            'invoice_payment_methods.*.label.required_if' => 'Donnez un nom à ce moyen de paiement.',
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
            'invoice_payment_methods.*.bank' => 'banque',
            'invoice_payment_methods.*.iban' => 'IBAN',
            'invoice_payment_methods.*.operator' => 'opérateur Mobile Money',
            'invoice_payment_methods.*.phone' => 'numéro Mobile Money',
            'invoice_payment_methods.*.payee' => 'ordre des chèques',
            'invoice_payment_methods.*.label' => 'nom du moyen de paiement',
        ];
    }
}
