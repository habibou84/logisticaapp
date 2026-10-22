<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Services\Push\WebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerte liée à une course (nouvelle course, incident, mission...).
 * Canaux : base de données (cloche), temps réel et, pour le livreur, notification push.
 */
class OrderAlert extends Notification implements ShouldQueue
{
    use Queueable;

    // Alertes envoyées aussi en notification push sur le téléphone du livreur
    public const PUSH_KINDS = ['new_mission', 'mission_removed', 'dispatch_message'];

    public function __construct(
        public Order $order,
        public string $kind,
        public string $title,
        public string $body,
        public array $extra = [],
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];

        // Téléphone du livreur prévenu même en veille : nouvelle mission, consigne de l'agence
        if (in_array($this->kind, self::PUSH_KINDS, true) && $notifiable instanceof User && $notifiable->isCourier()
            && WebPush::enabled() && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebPush(object $notifiable): array
    {
        $assignment = $this->extra['assignment_id'] ?? null;

        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $assignment ? "/livreur/missions/{$assignment}" : '/livreur',
            'tag' => $this->kind.'-'.$this->order->id,
            'urgent' => $this->kind === 'dispatch_message',
            // Nouvelle mission : vibration propre sur le téléphone du livreur
            'mission' => $this->kind === 'new_mission',
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
            'order_id' => $this->order->id,
            'tracking_code' => $this->order->tracking_code,
            'status' => $this->order->status->value,
            ...$this->extra,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
