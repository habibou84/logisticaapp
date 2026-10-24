<?php

namespace App\Notifications;

use App\Models\FieldEvent;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Services\Push\WebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Événement terrain : signalement d'un livreur (équipe), réponse ou prise en charge (livreur).
 * Cloche, temps réel et, pour le livreur, notification push.
 */
class FieldEventAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public FieldEvent $event,
        public string $kind,
        public string $title,
        public string $body,
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

    private function forCourier(object $notifiable): bool
    {
        return $notifiable instanceof User && $notifiable->isCourier();
    }

    private function href(object $notifiable): string
    {
        return $this->forCourier($notifiable) ? "/livreur/evenements/{$this->event->id}" : "/admin/terrain?evenement={$this->event->id}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->href($notifiable),
            'tag' => 'field-event-'.$this->event->id,
            'urgent' => true,
        ];
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
            'severity' => $this->severity,
            'field_event' => true,
            'event_id' => $this->event->id,
            'courier_id' => $this->event->courier_id,
            'href' => $this->href($notifiable),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
