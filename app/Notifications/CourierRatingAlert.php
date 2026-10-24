<?php

namespace App\Notifications;

use App\Models\Courier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Notation des livreurs : mauvaise note reçue, note sous le seuil, mauvaises notes répétées
 * (cloche et temps réel des responsables).
 */
class CourierRatingAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Courier $courier,
        public string $kind,
        public string $title,
        public string $body,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'severity' => 'warning',
            'courier_id' => $this->courier->id,
            'href' => "/admin/qualite-livreurs?livreur={$this->courier->id}",
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
