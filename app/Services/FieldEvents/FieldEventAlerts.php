<?php

namespace App\Services\FieldEvents;

use App\Enums\Role;
use App\Models\Company;
use App\Models\FieldEvent;
use App\Models\User;
use App\Notifications\FieldEventAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Alertes des événements terrain : à l'équipe au signalement (dispatch et responsables ; administrateurs aussi
 * pour un événement urgent si l'entreprise le veut), relances d'un événement que personne ne prend en charge,
 * réponses au livreur.
 */
class FieldEventAlerts
{
    public const SEVERITY_LABELS = ['info' => 'Info', 'hindrance' => 'Gênant', 'blocking' => 'Bloquant', 'urgent' => 'Urgent'];

    public const CAPACITY_LABELS = ['continue' => 'peut continuer', 'blocked' => 'bloqué', 'stopped' => 'arrête pour aujourd\'hui'];

    public function __construct(private readonly FieldEventSettings $settings) {}

    public function reported(FieldEvent $event): void
    {
        $event->loadMissing(['courier.user', 'company']);
        $urgent = $event->severity === 'urgent';
        $roles = [Role::Dispatcher->value, Role::ActivityManager->value];
        if ($urgent && $this->settings->for($event->company)['urgent_admins']) {
            $roles[] = Role::Admin->value;
        }
        $users = $this->staff($event->company_id, $roles);
        // Ni dispatch ni responsable : les administrateurs
        if ($users->isEmpty()) {
            $users = $this->staff($event->company_id, [Role::Admin->value]);
        }

        Notification::send($users, new FieldEventAlert($event, 'field_event', $this->title($event), $this->summary($event), $this->level($event)));
    }

    /**
     * Relance d'un événement resté sans prise en charge : 1 = dispatch et responsables, 2 = administrateurs.
     */
    public function remind(FieldEvent $event, int $level, int $minutes): void
    {
        $event->loadMissing('courier.user');
        $roles = $level === 1 ? [Role::Admin->value, Role::ActivityManager->value, Role::Dispatcher->value] : [Role::Admin->value];

        Notification::send($this->staff($event->company_id, $roles), new FieldEventAlert(
            $event,
            'field_event_reminder',
            "⏰ Toujours sans prise en charge : {$event->type_label} · {$event->courier?->user?->name}",
            "Signalé il y a {$minutes} min. Prenez-le en charge ou répondez au livreur.",
            'alert',
        ));
    }

    public function toCourier(FieldEvent $event, string $kind, string $title, string $body): void
    {
        $event->loadMissing('courier.user');
        $event->courier?->user?->notify(new FieldEventAlert($event, $kind, $title, $body, 'note'));
    }

    /**
     * Message ou reprise du livreur : à la personne qui suit l'événement, sinon au dispatch et aux responsables.
     */
    public function toStaff(FieldEvent $event, string $kind, string $title, string $body, string $severity = 'warning'): void
    {
        $users = $event->taken_by ? User::whereKey($event->taken_by)->get() : $this->staff($event->company_id, [Role::Dispatcher->value, Role::ActivityManager->value]);
        if ($users->isEmpty()) {
            $users = $this->staff($event->company_id, [Role::Admin->value]);
        }
        Notification::send($users, new FieldEventAlert($event, $kind, $title, $body, $severity));
    }

    public function title(FieldEvent $event): string
    {
        return "{$event->icon} {$event->type_label} · {$event->courier?->user?->name}";
    }

    public function summary(FieldEvent $event): string
    {
        $count = $event->orders()->count();
        $parts = [ucfirst(self::CAPACITY_LABELS[$event->capacity] ?? $event->capacity)];
        if ($event->capacity === 'blocked' && $event->blocked_until) {
            $tz = Company::query()->whereKey($event->company_id)->value('timezone') ?: config('app.timezone');
            $parts[0] .= ' jusqu\'à '.$event->blocked_until->setTimezone($tz)->format('H:i').' environ';
        }
        if ($count) {
            $parts[] = $count.' course'.($count > 1 ? 's' : '').' touchée'.($count > 1 ? 's' : '');
        }
        if ($event->note) {
            $parts[] = '« '.mb_strimwidth($event->note, 0, 120, '…').' »';
        }

        return implode(' · ', $parts);
    }

    /** Gravité de l'alerte (son et couleur côté équipe) */
    public function level(FieldEvent $event): string
    {
        return match ($event->severity) {
            'urgent', 'blocking' => 'alert',
            'hindrance' => 'warning',
            default => 'note',
        };
    }

    /**
     * @param  list<string>  $roles
     * @return Collection<int, User>
     */
    private function staff(int $companyId, array $roles): Collection
    {
        return User::forCompany($companyId)->where('status', 'active')->role($roles)->get();
    }
}
