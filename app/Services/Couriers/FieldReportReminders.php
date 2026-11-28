<?php

namespace App\Services\Couriers;

use App\Enums\OrderEventType;
use App\Enums\Role;
use App\Models\Company;
use App\Models\FieldEvent;
use App\Models\FieldReportReminder;
use App\Models\OrderEvent;
use App\Models\OrderProposal;
use App\Models\User;
use App\Notifications\OrderAlert;
use App\Services\FieldEvents\FieldEventAlerts;
use Illuminate\Support\Facades\Notification;

/**
 * Relance des problèmes signalés par les livreurs et restés sans suite :
 * 1re relance au dispatch après le délai de l'entreprise, 2e aux administrateurs
 * après trois fois ce délai. Rien n'est relancé si un dispatcher a répondu au
 * livreur ou traité la remontée, ni si la course est terminée. Les événements terrain (panne, accident…)
 * restés sans prise en charge sont relancés de la même façon.
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

        // Reports et retours à valider : relancés tant que personne n'a répondu, même sans relance des remontées
        Company::query()->each(function (Company $company) use (&$sent) {
            $sent += $this->proposals($company);
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
            // Report ou retour à valider : relancé à part (proposals)
            ->whereDoesntHave('proposal', fn ($q) => $q->withoutGlobalScopes()->where('status', OrderProposal::PENDING))
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
                ->role($level === 1 ? [Role::Admin->value, Role::ActivityManager->value, Role::Dispatcher->value] : [Role::Admin->value])
                ->get();

            if ($recipients->isNotEmpty()) {
                $label = $event->incidentReason?->label ?? $event->to_status?->label() ?? 'Mission refusée';
                Notification::send($recipients, new OrderAlert(
                    $event->order,
                    'field_reminder',
                    "Toujours sans suite : {$label} · {$event->order->tracking_code}",
                    ($event->actor_name ?? 'Le livreur')." l'a signalé il y a {$minutes} min. Répondez-lui ou marquez la remontée comme traitée.",
                    ['field' => true, 'severity' => 'alert', 'event_id' => $event->id, 'reminder' => $level],
                ));
            }

            $reminder->fill(['count' => $level, 'last_reminded_at' => now()])->save();
            $sent++;
        }

        return $sent + $this->fieldEvents($company, $delay);
    }

    /**
     * Report ou retour signalé par un livreur et pas encore validé (ni refusé) : l'équipe et le client sont relancés
     * à chaque délai de l'entreprise (une heure par défaut), puis une fois par jour après six relances.
     */
    private function proposals(Company $company): int
    {
        $interval = $company->field_alert_reminder_minutes > 0 ? (int) $company->field_alert_reminder_minutes : 60;
        $sent = 0;

        $proposals = OrderProposal::withoutGlobalScopes()->where('company_id', $company->id)
            ->where('status', OrderProposal::PENDING)
            ->where('created_at', '<=', now()->subMinutes($interval))
            ->whereHas('order', fn ($q) => $q->withoutGlobalScopes()->where('status', 'delivery_failed')
                ->whereDoesntHave('assignments', fn ($a) => $a->active()))
            ->with(['order' => fn ($q) => $q->withoutGlobalScopes(), 'reason', 'proposer'])
            ->get();

        foreach ($proposals as $proposal) {
            $since = $proposal->last_reminded_at ?? $proposal->created_at;
            $wait = $proposal->reminders >= 6 ? 24 * 60 : $interval;
            if ($since->gt(now()->subMinutes($wait))) {
                continue;
            }

            $order = $proposal->order;
            $hours = max(1, (int) round($proposal->created_at->diffInMinutes(now()) / 60));
            $what = $proposal->summary();
            $staff = User::forCompany($company->id)->where('status', 'active')
                ->role([Role::Admin->value, Role::ActivityManager->value, Role::Dispatcher->value])->get();
            if ($staff->isNotEmpty()) {
                Notification::send($staff, new OrderAlert($order, 'proposal_reminder',
                    "Toujours à valider : {$order->tracking_code}",
                    ($proposal->proposer?->name ?? 'Le livreur')." : {$proposal->reason?->label} · {$what}, signalé il y a {$hours} h. Validez ou refusez.",
                    ['field' => true, 'severity' => 'alert', 'event_id' => $proposal->order_event_id, 'reminder' => $proposal->reminders + 1]));
            }
            $merchant = User::where('merchant_id', $order->merchant_id)->where('status', 'active')->get()
                ->filter(fn (User $u) => $u->can('orders.create'));
            if ($merchant->isNotEmpty()) {
                Notification::send($merchant, new OrderAlert($order, 'proposal_reminder',
                    "À confirmer : {$order->tracking_code}",
                    "Le livreur indique : {$proposal->reason?->label} · {$what}. Confirmez-le ou refusez-le dans le suivi du colis."));
            }

            $proposal->forceFill(['reminders' => $proposal->reminders + 1, 'last_reminded_at' => now()])->save();
            $sent++;
        }

        return $sent;
    }

    /**
     * Événements terrain que personne n'a pris en charge (ni réponse au livreur) : mêmes délais.
     */
    private function fieldEvents(Company $company, int $delay): int
    {
        $sent = 0;
        $events = FieldEvent::withoutGlobalScopes()->where('company_id', $company->id)
            ->where('status', FieldEvent::OPEN)
            ->where('created_at', '>=', now()->subDay())
            ->where('created_at', '<=', now()->subMinutes($delay))
            ->where('reminders', '<', 2)
            ->whereDoesntHave('messages', fn ($q) => $q->where('from_courier', false))
            ->get();

        foreach ($events as $event) {
            $minutes = (int) $event->created_at->diffInMinutes(now());
            $level = match ($event->reminders) {
                0 => 1,
                1 => $minutes >= 3 * $delay ? 2 : null,
                default => null,
            };
            if ($level === null) {
                continue;
            }
            app(FieldEventAlerts::class)->remind($event, $level, $minutes);
            $event->forceFill(['reminders' => $level, 'last_reminded_at' => now()])->saveQuietly();
            $sent++;
        }

        return $sent;
    }
}
