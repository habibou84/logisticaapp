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
 * Message du matin au livreur : ce qu'il ramasse (ou livre) aujourd'hui selon le planning.
 */
class PlanningDigestNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $lines  « Ramassage : Cocody, Riviera » …
     */
    public function __construct(public string $date, public array $lines) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];
        if ($notifiable instanceof User && WebPush::enabled() && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebPush(object $notifiable): array
    {
        return ['title' => '📅 Votre journée', 'body' => implode(' · ', $this->lines), 'url' => '/livreur', 'tag' => "planning-{$this->date}"];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'planning_digest', 'title' => '📅 Votre journée', 'body' => 'Aujourd\'hui : '.implode(' · ', $this->lines), 'date' => $this->date, 'href' => '/livreur'];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
