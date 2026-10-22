<?php

namespace App\Services\Orders;

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Chaque matin, rappelle au dispatch les courses à livrer aujourd'hui (ou avant) et pas encore
 * assignées : reports arrivés à échéance et colis ramassés en avance pour aujourd'hui. Ils sont
 * revenus dans « À livrer ».
 */
class DueTodayReminder
{
    /**
     * @return int nombre d'entreprises prévenues
     */
    public function run(): int
    {
        $sent = 0;

        Company::query()->each(function (Company $company) use (&$sent) {
            // Une fois par jour et par entreprise, même si la commande est relancée
            if (Cache::add("due-today:{$company->id}:".today()->toDateString(), true, now()->endOfDay())) {
                $sent += (int) $this->forCompany($company);
            }
        });

        return $sent;
    }

    private function forCompany(Company $company): bool
    {
        $orders = Order::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('status', OrderStatus::values([OrderStatus::Rescheduled, OrderStatus::PickedUp, OrderStatus::AtHub]))
            ->where('return_requested', false)
            ->whereNotNull('delivery_scheduled_date')
            ->whereDate('delivery_scheduled_date', '<=', today())
            ->whereDoesntHave('assignments', fn ($q) => $q->active()->where('type', AssignmentType::Delivery->value))
            ->with(['holder' => fn ($q) => $q->withoutGlobalScopes()->with('user')])
            ->orderBy('delivery_scheduled_date')
            ->get();

        if ($orders->isEmpty()) {
            return false;
        }

        $recipients = User::forCompany($company->id)->where('status', 'active')
            ->role([Role::Admin->value, Role::Dispatcher->value])
            ->get();

        if ($recipients->isEmpty()) {
            return false;
        }

        $count = $orders->count();
        $late = $orders->filter(fn (Order $o) => $o->delivery_scheduled_date->lt(today()))->count();
        $rescheduled = $orders->where('status', OrderStatus::Rescheduled)->count();
        $early = $count - $rescheduled;

        // Suggestion : confier la course au livreur qui a déjà le colis
        $holders = $orders->filter(fn (Order $o) => $o->holder)->groupBy(fn (Order $o) => $o->holder->user?->name)
            ->map(fn ($group, $name) => "{$group->count()} chez {$name}")->values();

        $body = ($early && $rescheduled ? "{$rescheduled} reportée(s) et {$early} colis ramassé(s) en avance : " : '')
            .$orders->pluck('tracking_code')->take(6)->implode(', ').($count > 6 ? '…' : '').'.'
            .($late ? " Dont {$late} en retard sur la date prévue." : '')
            .($holders->isNotEmpty() ? ' Colis déjà en main : '.$holders->implode(', ').' (à leur confier en priorité).' : '');

        Notification::send($recipients, new OrderAlert(
            $orders->first(),
            'due_today',
            match (true) {
                $count === 1 && $rescheduled === 1 => "📅 Course {$orders->first()->tracking_code} reportée à aujourd'hui",
                $count === 1 => "📅 Course {$orders->first()->tracking_code} prévue aujourd'hui (colis déjà ramassé)",
                $early === 0 => "📅 {$count} courses reportées à livrer aujourd'hui",
                default => "📅 {$count} courses prévues à livrer aujourd'hui",
            },
            $body,
            ['href' => '/admin/courses?queue=to_deliver', 'count' => $count],
        ));

        return true;
    }
}
