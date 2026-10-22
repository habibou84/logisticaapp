<?php

namespace App\Services\Couriers;

use App\Enums\OrderEventType;
use App\Enums\Role;
use App\Models\Company;
use App\Models\FieldReportReminder;
use App\Models\OrderEvent;
use App\Models\User;
use App\Notifications\OrderAlert;
use Illuminate\Support\Facades\Notification;

/**
 * Relance des problèmes signalés par les livreurs et restés sans suite :
 * 1re relance au dispatch après le délai de l'entreprise, 2e aux administrateurs
 * après trois fois ce délai. Rien n'est relancé si un dispatcher a répondu au
 * livreur ou traité la remontée, ni si la course est terminée.
 */
class FieldReportReminders
{
    /**
     * @return int nombre de relances envoyées
     */
    public function run(): int
    {
        $sent = 0;

        Company::query()->where('field_alert_reminder_minutes', '>', 0)->each(function (Company $company) use (&$sent) {
            $sent += $this->forCompany($company);
        });

        return $sent;
    }

    private function forCompany(Company $company): int
    {
        $delay = $company->field_alert_reminder_minutes;

        $events = OrderEvent::query()
            ->fieldReports()
            // Problèmes et refus de mission uniquement (pas les notes ni les frais)
            ->whereNotIn('type', [OrderEventType::Note->value, OrderEventType::ExpenseAdded->value])
            ->whereHas('order', fn ($q) => $q->withoutGlobalScopes()->where('company_id', $company->id)
                ->whereNotIn('status', ['delivered', 'returned', 'cancelled', 'rejected']))
            ->where('created_at', '>=', now()->subDay())
            ->where('created_at', '<=', now()->subMinutes($delay))
            ->whereDoesntHave('review')
            ->whereDoesntHave('replies')
            ->with(['order' => fn ($q) => $q->withoutGlobalScopes(), 'incidentReason'])
            ->get();

        $sent = 0;

        foreach ($events as $event) {
            $reminder = FieldReportReminder::withoutGlobalScopes()->firstOrNew(['order_event_id' => $event->id], ['company_id' => $company->id]);
            $minutes = (int) $event->created_at->diffInMinutes(now());

            $count = (int) $reminder->count;
            $level = match (true) {
                $count === 0 => 1,
                $count === 1 && $minutes >= 3 * $delay => 2,
                default => null,
            };

            if ($level === null) {
                continue;
            }

            $recipients = User::forCompany($company->id)->where('status', 'active')
                ->role($level === 1 ? [Role::Admin->value, Role::Dispatcher->value] : [Role::Admin->value])
                ->get();

            if ($recipients->isNotEmpty()) {
                $label = $event->incidentReason?->label ?? $event->to_status?->label() ?? 'Mission refusée';
                Notification::send($recipients, new OrderAlert(
                    $event->order,
                    'field_reminder',
                    "⏰ Toujours sans suite : {$label} · {$event->order->tracking_code}",
                    ($event->actor_name ?? 'Le livreur')." l'a signalé il y a {$minutes} min. Répondez-lui ou marquez la remontée comme traitée.",
                    ['field' => true, 'severity' => 'alert', 'event_id' => $event->id, 'reminder' => $level],
                ));
            }

            $reminder->fill(['count' => $level, 'last_reminded_at' => now()])->save();
            $sent++;
        }

        return $sent;
    }
}
