<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Commande reçue sur la page du marchand : cloche et temps réel de l'application marchand.
 */
class ShopOrderReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order) {}

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
            'kind' => 'shop_order',
            'title' => '🛍️ Nouvelle commande sur votre boutique',
            'body' => trim("{$this->order->recipient_name} · {$this->order->description} · à encaisser : ".Money::format($this->order->cod_amount)),
            'order_id' => $this->order->id,
            'tracking_code' => $this->order->tracking_code,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
