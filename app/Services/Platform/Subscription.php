<?php

namespace App\Services\Platform;

use App\Enums\OrderStatus;
use App\Enums\PlatformFeature;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Abonnement d'une entreprise à la plateforme : formule, essai, consommation du mois
 * (courses, livreurs) et limites.
 */
class Subscription
{
    /**
     * Courses du mois en cours (heure de l'entreprise), hors courses annulées ou refusées.
     */
    public function ordersThisMonth(Company $company): int
    {
        $start = CarbonImmutable::now($company->timezone)->startOfMonth()->utc();

        return Order::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('created_at', '>=', $start)
            ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Rejected->value])
            ->count();
    }

    /**
     * Livreurs actifs (comptes non suspendus).
     */
    public function activeCouriers(Company $company): int
    {
        return User::query()->where('company_id', $company->id)
            ->where('status', UserStatus::Active->value)
            ->role(Role::Courier->value)
            ->count();
    }

    /**
     * Refuse un livreur actif de plus quand la formule est au maximum.
     *
     * @throws ValidationException
     */
    public function ensureCourierRoom(Company $company, string $field = 'role'): void
    {
        $max = $company->loadMissing('plan')->plan?->max_couriers;

        if ($max !== null && $this->activeCouriers($company) >= $max) {
            throw ValidationException::withMessages([
                $field => "Votre formule « {$company->plan->name} » permet {$max} livreur(s) actif(s) : suspendez un livreur ou passez à une formule supérieure.",
            ]);
        }
    }

    /**
     * État de l'abonnement, pour la console et la page « Mon abonnement ».
     *
     * @return array<string, mixed>
     */
    public function summary(Company $company): array
    {
        $plan = $company->loadMissing('plan')->plan;
        $orders = $this->ordersThisMonth($company);
        $included = $plan?->included_orders;
        $overageOrders = $plan && $included !== null ? max(0, $orders - $included) : 0;
        $overageAmount = $overageOrders * ($plan?->overage_per_order ?? 0);

        return [
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'description' => $plan->description,
                'price_monthly' => $plan->price_monthly,
                'included_orders' => $included,
                'overage_per_order' => $plan->overage_per_order,
                'max_couriers' => $plan->max_couriers,
            ] : null,
            'trial_ends_at' => $company->trial_ends_at?->toDateString(),
            'on_trial' => $company->onTrial(),
            'month' => CarbonImmutable::now($company->timezone)->format('Y-m'),
            'usage' => [
                'orders' => $orders,
                'included_orders' => $included,
                'overage_orders' => $overageOrders,
                'couriers' => $this->activeCouriers($company),
                'max_couriers' => $plan?->max_couriers,
            ],
            // Montant estimé du mois (forfait + dépassement) ; rien pendant l'essai
            'estimate' => [
                'base' => $plan?->price_monthly ?? 0,
                'overage' => $overageAmount,
                'total' => $company->onTrial() ? 0 : ($plan?->price_monthly ?? 0) + $overageAmount,
            ],
            'features' => array_map(fn (PlatformFeature $f) => [
                'key' => $f->value,
                'label' => $f->label(),
                'description' => $f->description(),
                'included' => $company->hasFeature($f),
            ], PlatformFeature::cases()),
        ];
    }
}
