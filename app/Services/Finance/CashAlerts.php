<?php

namespace App\Services\Finance;

use App\Enums\Role;
use App\Enums\WhatsAppTemplate;
use App\Models\Company;
use App\Models\Courier;
use App\Models\User;
use App\Notifications\CashHeldAlert;
use App\Services\Messaging\Messenger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * Argent gardé trop longtemps par un livreur (au-delà du délai de l'entreprise, 24 h par défaut) ou au-delà de son
 * plafond : les responsables de la caisse sont prévenus dans l'application et, si l'entreprise le veut, le livreur
 * reçoit un rappel par WhatsApp. Une alerte par livreur et par jour au plus.
 */
class CashAlerts
{
    public function __construct(private readonly CourierCash $cash, private readonly Messenger $messenger) {}

    /** Livreurs « à surveiller » : solde à remettre, ancienneté et raison */
    public function watched(Company $company, array $balances): array
    {
        $watch = [];
        foreach ($balances as $courierId => $balance) {
            $reason = $this->reason($company, $balance);
            if ($reason !== null) {
                $watch[$courierId] = $reason;
            }
        }

        return $watch;
    }

    /**
     * @param  array{due: int, oldest: ?string}  $balance
     */
    public function reason(Company $company, array $balance): ?string
    {
        if ($balance['due'] <= 0) {
            return null;
        }
        $hours = (int) $company->cash_alert_hours;
        $oldest = $balance['oldest'] ? CarbonImmutable::parse($balance['oldest']) : null;
        if ($hours > 0 && $oldest !== null && $oldest->lte(now()->subHours($hours))) {
            $days = (int) floor($oldest->diffInHours(now()) / 24);

            return 'Garde l\'argent depuis '.($days >= 1 ? $days.' jour'.($days > 1 ? 's' : '') : $hours.' h');
        }
        if ($company->cash_alert_amount !== null && $company->cash_alert_amount > 0 && $balance['due'] > $company->cash_alert_amount) {
            return 'Au-dessus du plafond de '.Money::format($company->cash_alert_amount);
        }

        return null;
    }

    /**
     * @return int nombre de livreurs signalés
     */
    public function run(): int
    {
        $sent = 0;
        Company::query()->where('status', 'active')->each(function (Company $company) use (&$sent) {
            $sent += $this->forCompany($company);
        });

        return $sent;
    }

    public function forCompany(Company $company): int
    {
        $balances = $this->cash->balances($company->id);
        $watched = $this->watched($company, $balances);
        if ($watched === []) {
            return 0;
        }

        $couriers = Courier::withoutGlobalScopes()->whereIn('id', array_keys($watched))->with('user')->get()
            // Une alerte par livreur et par jour
            ->filter(fn (Courier $c) => $c->cash_alerted_at === null || ! $c->cash_alerted_at->isToday());
        if ($couriers->isEmpty()) {
            return 0;
        }

        $staff = User::forCompany($company->id)->where('status', 'active')->whereNull('merchant_id')
            ->role([Role::Admin->value, Role::Cashier->value])->get();

        foreach ($couriers as $courier) {
            $balance = $balances[$courier->id];
            if ($staff->isNotEmpty()) {
                Notification::send($staff, new CashHeldAlert($courier, $balance['due'], $watched[$courier->id]));
            }
            if ($company->cash_alert_courier && $courier->user?->phone) {
                $this->messenger->whatsapp($company->id, $courier->user->phone, 'courier', WhatsAppTemplate::CashReminder, [
                    $courier->user->name,
                    Money::format($balance['due']),
                    CarbonImmutable::parse($balance['oldest'] ?? now())->format('d/m à H:i'),
                    $company->name,
                ], ['event' => 'cash.reminder']);
            }
            $courier->forceFill(['cash_alerted_at' => now()])->save();
        }

        return $couriers->count();
    }
}
