<?php

namespace App\Http\Resources\V1;

use App\Enums\Activity;
use App\Models\Company;
use App\Services\Departments\DepartmentSettings;
use App\Services\Destinations\DestinationSettings;
use App\Services\FieldEvents\FieldEventSettings;
use App\Services\Orders\ActivityOptions;
use App\Services\Ratings\RatingSettings;
use App\Services\Vehicles\VehicleSettings;
use App\Support\Branding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Company
 */
class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'auto_confirm_orders' => $this->auto_confirm_orders,
            'validation_mode' => $this->validation_mode,
            'default_max_attempts' => $this->default_max_attempts,
            'require_delivery_code' => $this->require_delivery_code,
            'offline_courier_actions' => $this->offline_courier_actions,
            'return_fee_percent' => $this->return_fee_percent,
            'field_alert_reminder_minutes' => $this->field_alert_reminder_minutes,
            'parcel_hold_alert_hours' => $this->parcel_hold_alert_hours,
            'pickup_mission_custody' => $this->pickup_mission_custody ?? 'choose',
            'depot_closes_at' => $this->depot_closes_at,
            'pickup_assign_alert_minutes' => $this->pickup_assign_alert_minutes,
            'daily_cutoff_time' => $this->daily_cutoff_time,
            'delivery_assign_alert_minutes' => $this->delivery_assign_alert_minutes,
            'auto_pickup' => $this->auto_pickup ?? 'off',
            'mission_acceptance' => $this->mission_acceptance ?? 'choose',
            'unreachable_minutes' => $this->unreachable_minutes ?? 20,
            'late_pickup_alert_minutes' => $this->late_pickup_alert_minutes ?? 60,
            'courier_available_alert' => (bool) ($this->courier_available_alert ?? true),
            'directory_listed' => (bool) $this->directory_listed,
            'directory_pitch' => $this->directory_pitch,
            // Lien à envoyer aux clients : Jibiat Marchand leur offre un mois d'essai en plus (entreprise de l'annuaire)
            'jibiat_marchand_invite_url' => config('services.jibiat_marchand.url').'/creer-ma-boutique?partenaire='.rawurlencode((string) $this->slug),
            'automation_paused_at' => $this->automation_paused_at,
            'auto_delivery' => $this->auto_delivery ?? 'off',
            'auto_delivery_max_cod' => $this->auto_delivery_max_cod,
            'same_courier_delivery' => (bool) $this->same_courier_delivery,
            'cutoff_by_day' => (object) ($this->cutoff_by_day ?? []),
            'tagline' => $this->tagline,
            'invoice_payment_info' => $this->invoice_payment_info,
            'invoice_payment_methods' => $this->invoice_payment_methods ?? [],
            // Délai interne de distribution par activité (heures, vide : aucun)
            'internal_delays' => (object) ($this->internal_delays ?? []),
            // Distribution et MAD : réglages de l'entreprise, valeurs par défaut comprises
            'activity_options' => collect(ActivityOptions::ACTIVITIES)
                ->mapWithKeys(fn (Activity $a) => [$a->value => app(ActivityOptions::class)->company($this->resource, $a)]),
            // Notation des livreurs (valeurs par défaut comprises)
            'rating_settings' => app(RatingSettings::class)->for($this->resource),
            'field_event_settings' => app(FieldEventSettings::class)->for($this->resource),
            'vehicle_settings' => app(VehicleSettings::class)->for($this->resource),
            'department_settings' => app(DepartmentSettings::class)->company($this->resource),
            'destination_settings' => app(DestinationSettings::class)->for($this->resource),
            'merchant_signup' => $this->merchant_signup,
            'weekly_report' => $this->weekly_report,
            'guide_videos' => $this->guide_videos,
            'logo_url' => Branding::logoUrl($this->resource),
            'status' => $this->status,
            'users_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
