<?php

namespace App\Events;

use App\Models\Order;
use App\Models\OrderEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Diffusé en temps réel au personnel de l'entreprise et au marchand concerné
 * à chaque action sur une course (création, statut, assignation, note...).
 */
class OrderChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order, public OrderEvent $event) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('company.'.$this->order->company_id)];

        if ($this->event->visible_to_merchant) {
            $channels[] = new PrivateChannel('merchant.'.$this->order->merchant_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'order.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'order' => [
                'id' => $this->order->id,
                'tracking_code' => $this->order->tracking_code,
                'merchant_id' => $this->order->merchant_id,
                'status' => $this->order->status->value,
                'status_label' => $this->order->statusLabel(),
            ],
            'event' => [
                'id' => $this->event->id,
                'type' => $this->event->type->value,
                'actor_name' => $this->event->actor_name,
                'note' => $this->event->visible_to_merchant ? $this->event->note : null,
            ],
        ];
    }
}
