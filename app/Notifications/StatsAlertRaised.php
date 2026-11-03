<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerte statistique (taux de réussite d'une zone, échecs d'un livreur, client en baisse) : cloche et temps réel.
 */
class StatsAlertRaised extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $key, public string $title, public string $body, public string $href) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'stats_alert', 'key' => $this->key, 'title' => $this->title, 'body' => $this->body, 'href' => $this->href, 'severity' => 'warning'];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
