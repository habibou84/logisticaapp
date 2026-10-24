<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Services\Push\WebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Véhicules : papier ou entretien à prévoir, problème à l'état des lieux, versement en retard, consommation
 * anormale… Cloche et temps réel ; notification push pour le livreur.
 */
class VehicleAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $kind,
        public string $title,
        public string $body,
        public string $href,
        public string $severity = 'warning',
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];
        if ($notifiable instanceof User && $notifiable->isCourier() && WebPush::enabled() && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebPush(object $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'url' => $this->href, 'tag' => 'vehicle-'.$this->kind];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'body' => $this->body, 'severity' => $this->severity, 'vehicle' => true, 'href' => $this->href];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
