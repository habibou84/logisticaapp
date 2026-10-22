<?php

namespace App\Notifications;

use App\Models\Courier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Livreur libre (ou bientôt libre) avec des ramassages près de lui : cloche et temps réel
 * du dispatch, qui peut lui confier une mission ramassage.
 */
class CourierAvailableAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $row  ligne du tableau de disponibilité (CourierAvailability)
     */
    public function __construct(
        public Courier $courier,
        public array $row,
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
        $free = $this->row['state'] === 'free';
        $where = $this->row['arrival_zone']['name'] ?? null;
        $count = $this->row['nearby']['count'];
        $stops = $this->row['nearby']['stops'];
        $left = $this->row['remaining']['total'];

        return [
            'kind' => 'courier_available',
            'title' => ($free ? '🟢 ' : '🟡 ').$this->row['name'].($free ? ' est libre' : ' est bientôt libre').($where ? " à {$where}" : ''),
            'body' => ($free ? 'Toutes ses missions sont terminées. ' : "Plus que {$left} mission".($left > 1 ? 's' : '').' en cours. ')
                ."{$count} colis à ramasser près de lui"
                .($stops > 1 ? " ({$stops} boutiques)" : '')
                .'. Confiez-lui une mission ramassage.',
            'severity' => 'note',
            'courier_id' => $this->courier->id,
            'href' => "/admin/disponibilites?livreur={$this->courier->id}",
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
