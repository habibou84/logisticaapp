<?php

namespace App\Notifications;

use App\Models\WebhookSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Adresse webhook coupée après trop d'échecs d'affilée : le marchand est prévenu (cloche, temps réel)
 * pour la réparer dans son application, puis la réactiver.
 */
class WebhookDisabled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public WebhookSubscription $subscription) {}

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
            'kind' => 'webhook_disabled',
            'title' => '🔌 Liaison avec votre application interrompue',
            'body' => ($this->subscription->description ?: $this->subscription->url).' ne répond plus : les mises à jour de vos courses ne lui sont plus envoyées. Reconnectez votre application de livraison.',
            'severity' => 'alert',
            'href' => '/marchand/integrations',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
