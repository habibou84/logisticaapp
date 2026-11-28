<?php

namespace App\Notifications;

use App\Models\ReturnHandover;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Remise au client à vérifier : faite sans sa confirmation (remise de secours) ou contestée (« il en manque »).
 */
class ReturnHandoverToReview extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ReturnHandover $handover) {}

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
        $merchant = $this->handover->merchant?->business_name ?? 'Le client';
        $courier = $this->handover->courier?->user?->name ?? 'le livreur';
        $count = count($this->handover->order_ids ?? []);
        $disputed = $this->handover->status === ReturnHandover::DISPUTED;

        return [
            'kind' => 'return_handover',
            'title' => $disputed ? "{$merchant} signale qu'il manque des colis" : "Remise sans confirmation : {$merchant}",
            'body' => $disputed
                ? "Remise {$this->handover->reference} de {$courier} ({$count} colis) : appelez le client et le livreur."
                : "{$courier} a rendu {$count} colis sans confirmation du client (".(ReturnHandover::FALLBACK_REASONS[$this->handover->fallback_reason] ?? 'motif inconnu').').',
            'return_handover_id' => $this->handover->id,
            'href' => '/admin/retours',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
