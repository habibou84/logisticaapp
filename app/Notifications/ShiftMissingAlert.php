<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Livreurs qui n'ont pas pointé à leur lieu de début après le délai choisi : cloche et temps réel du dispatch.
 */
class ShiftMissingAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array<string, mixed>>  $rows  lignes de l'écran des pointages (ShiftBoard)
     */
    public function __construct(public array $rows) {}

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
        $count = count($this->rows);
        $names = collect($this->rows)->map(fn (array $r) => $r['name'].' ('.$r['place']['label'].', '.$r['late_now'].' min)')->take(5)->implode(', ');

        return [
            'kind' => 'shift_missing',
            'title' => $count === 1 ? "⏰ {$this->rows[0]['name']} n'a pas encore pointé" : "⏰ {$count} livreurs n'ont pas encore pointé",
            'body' => $names.($count > 5 ? '…' : '').'. Appelez-les ou pointez-les à la main si besoin.',
            'severity' => 'warning',
            'href' => '/admin/pointages',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
