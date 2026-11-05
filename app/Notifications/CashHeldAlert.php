<?php

namespace App\Notifications;

use App\Models\Courier;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Argent gardé trop longtemps (ou au-delà du plafond) par un livreur : cloche et temps réel de la caisse.
 */
class CashHeldAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Courier $courier, public int $amount, public string $reason) {}

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
        $name = $this->courier->user?->name ?? 'Un livreur';

        return [
            'kind' => 'cash_held',
            'title' => "💵 {$name} garde ".Money::format($this->amount),
            'body' => $this->reason.'. Recevez son versement à la caisse.',
            'severity' => 'alert',
            'courier_id' => $this->courier->id,
            'href' => '/admin/caisse?surveiller=1',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
