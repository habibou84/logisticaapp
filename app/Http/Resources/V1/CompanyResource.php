<?php

namespace App\Http\Resources\V1;

use App\Models\Company;
use App\Services\Platform\Billing;
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
            // Lien à envoyer aux e-commerçants : Jibiat Marchand leur offre un mois d'essai en plus (entreprise de l'annuaire)
            'jibiat_marchand_invite_url' => config('services.jibiat_marchand.url').'/creer-ma-boutique?partenaire='.rawurlencode((string) $this->slug),
            'automation_paused_at' => $this->automation_paused_at,
            'auto_delivery' => $this->auto_delivery ?? 'off',
            'auto_delivery_max_cod' => $this->auto_delivery_max_cod,
            'same_courier_delivery' => (bool) $this->same_courier_delivery,
            'cutoff_by_day' => (object) ($this->cutoff_by_day ?? []),
            'tagline' => $this->tagline,
            'merchant_signup' => $this->merchant_signup,
            'weekly_report' => $this->weekly_report,
            'logo_url' => Branding::logoUrl($this->resource),
            'status' => $this->status,
            // Formule : modules disponibles (menus et pages de l'application)
            'features' => $this->resource->features(),
            'plan' => $this->whenLoaded('plan', fn () => $this->plan ? ['id' => $this->plan->id, 'name' => $this->plan->name] : null),
            'trial_ends_at' => $this->trial_ends_at?->toDateString(),
            // Facture impayée (bandeau du back-office) : seulement pour qui gère l'abonnement
            'billing_state' => $this->when(
                $this->plan_id !== null && (bool) $request->user()?->can('settings.manage') && ! $request->user()->isSuperAdmin(),
                fn () => app(Billing::class)->state($this->resource)['state'],
            ),
            'users_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
