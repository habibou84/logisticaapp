<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Facture émise, rappel, retard, forfait bientôt épuisé : cloche de l'application du client
 * (toujours ; WhatsApp et push selon ses réglages, par Messenger).
 */
class ClientBillingAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $kind, public string $title, public string $body, public string $href) {}

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
        return ['kind' => $this->kind, 'title' => $this->title, 'body' => $this->body, 'href' => $this->href];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
