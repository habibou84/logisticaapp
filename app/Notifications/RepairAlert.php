<?php

namespace App\Notifications;

use App\Models\Repair;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Services\Push\WebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Atelier : panne proposée ou confiée à un mécanicien, achat sur place accordé ou refusé (mécanicien) ; panne
 * sans mécanicien, achat à accorder, intervention terminée (équipe). Cloche, temps réel et, pour le mécanicien,
 * notification push.
 */
class RepairAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Repair $repair,
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

        if ($this->forMechanic($notifiable) && WebPush::enabled() && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    private function forMechanic(object $notifiable): bool
    {
        return $notifiable instanceof User && $notifiable->isMechanic();
    }

    private function href(object $notifiable): string
    {
        return $this->forMechanic($notifiable) ? "/mecanicien/pannes/{$this->repair->id}" : "/admin/atelier?intervention={$this->repair->id}";
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
            'tag' => 'repair-'.$this->repair->id,
            'urgent' => true,
            // Proposition d'une panne : vibration longue, comme une mission proposée au livreur
            'mission' => in_array($this->kind, ['repair_offered', 'repair_assigned'], true),
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
            'repair_id' => $this->repair->id,
            'href' => $this->href($notifiable),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
