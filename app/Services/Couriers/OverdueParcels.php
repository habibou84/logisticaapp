<?php

namespace App\Services\Couriers;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderAlert;
use Illuminate\Support\Facades\Notification;

/**
 * Alerte le dispatch quand des colis restent chez un livreur au-delà du délai de
 * l'entreprise (24 h par défaut). Une seule alerte par colis et par garde,
 * regroupée par livreur.
 */
class OverdueParcels
{
    /**
     * @return int nombre d'alertes envoyées
     */
    public function run(): int
    {
        $sent = 0;

        Company::query()->where('status', 'active')->where('parcel_hold_alert_hours', '>', 0)->each(function (Company $company) use (&$sent) {
            $sent += $this->forCompany($company);
        });

        return $sent;
    }

    private function forCompany(Company $company): int
    {
        return $this->overdue($company) + $this->escalate($company);
    }

    /**
     * Colis toujours chez le livreur après trois fois le délai : les administrateurs
     * sont prévenus qu'il est peut-être perdu (à retrouver ou à déclarer perdu).
     */
    private function escalate(Company $company): int
    {
        $orders = Order::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNotNull('held_by_courier_id')
            ->whereNull('hold_escalated_at')
            ->where('held_since', '<=', now()->subHours(3 * $company->parcel_hold_alert_hours))
            // Gardé par le livreur pour sa livraison à venir (mission ramassage) : pas en retard
            ->tap(fn ($q) => PickupCustody::excludeKeptForLater($q, $company))
            ->with(['holder' => fn ($q) => $q->withoutGlobalScopes()->with('user')])
            ->orderBy('held_since')
            ->get();

        if ($orders->isEmpty()) {
            return 0;
        }

        $admins = User::forCompany($company->id)->where('status', 'active')->role(Role::Admin->value)->get();
        $sent = 0;

        foreach ($orders->groupBy('held_by_courier_id') as $courierId => $parcels) {
            $name = $parcels->first()->holder?->user?->name ?? 'un livreur';
            $days = (int) floor($parcels->first()->held_since->diffInHours(now()) / 24);

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new OrderAlert(
                    $parcels->first(),
                    'parcel_maybe_lost',
                    "🚨 Colis peut-être perdu{$this->plural($parcels->count())} chez {$name}",
                    $parcels->pluck('tracking_code')->take(5)->implode(', ')." : chez le livreur depuis {$days} jour(s). Retrouvez le colis ou déclarez-le perdu.",
                    ['custody' => true, 'severity' => 'alert', 'courier_id' => (int) $courierId, 'count' => $parcels->count(), 'href' => '/admin/colis-livreurs'],
                ));
                $sent++;
            }

            Order::withoutGlobalScopes()->whereKey($parcels->modelKeys())->update(['hold_escalated_at' => now()]);
        }

        return $sent;
    }

    private function overdue(Company $company): int
    {
        $orders = Order::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNotNull('held_by_courier_id')
            ->whereNull('hold_alerted_at')
            ->where('held_since', '<=', now()->subHours($company->parcel_hold_alert_hours))
            // Gardé par le livreur pour sa livraison à venir (mission ramassage) : pas en retard
            ->tap(fn ($q) => PickupCustody::excludeKeptForLater($q, $company))
            ->with(['holder' => fn ($q) => $q->withoutGlobalScopes()->with('user')])
            ->orderBy('held_since')
            ->get();

        if ($orders->isEmpty()) {
            return 0;
        }

        $recipients = User::forCompany($company->id)->where('status', 'active')
            ->role([Role::Admin->value, Role::Dispatcher->value])
            ->get();

        $sent = 0;

        foreach ($orders->groupBy('held_by_courier_id') as $courierId => $parcels) {
            $oldest = $parcels->first();
            $name = $oldest->holder?->user?->name ?? 'un livreur';
            $count = $parcels->count();
            $days = (int) floor($oldest->held_since->diffInHours(now()) / 24);
            $age = $days >= 1 ? "{$days} jour".($days > 1 ? 's' : '') : $company->parcel_hold_alert_hours.' h';

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new OrderAlert(
                    $oldest,
                    'parcel_overdue',
                    $count > 1 ? "📦 {$count} colis toujours chez {$name}" : "📦 Colis {$oldest->tracking_code} toujours chez {$name}",
                    "Non rendu{$this->plural($count)} au dépôt depuis plus de {$age} : ".$parcels->pluck('tracking_code')->take(5)->implode(', ').($count > 5 ? '…' : '').'.',
                    ['custody' => true, 'severity' => 'alert', 'courier_id' => (int) $courierId, 'count' => $count, 'href' => '/admin/colis-livreurs'],
                ));
                $sent++;
            }

            Order::withoutGlobalScopes()->whereKey($parcels->modelKeys())->update(['hold_alerted_at' => now()]);
        }

        return $sent;
    }

    private function plural(int $count): string
    {
        return $count > 1 ? 's' : '';
    }
}
