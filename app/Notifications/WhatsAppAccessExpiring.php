<?php

namespace App\Notifications;

use App\Models\WhatsAppAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Accès de Jibiat au compte WhatsApp de l'entreprise bientôt expiré (ou expiré) :
 * cloche des administrateurs, avec le lien vers le bouton « Connecter WhatsApp ».
 */
class WhatsAppAccessExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public WhatsAppAccount $account) {}

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
        $expired = $this->account->token_expires_at?->isPast() ?? false;

        return [
            'kind' => 'whatsapp_access',
            'title' => $expired ? 'WhatsApp déconnecté : accès Meta expiré' : 'Accès WhatsApp à renouveler avant le '.$this->account->token_expires_at?->format('d/m/Y'),
            'body' => $expired
                ? 'Les messages WhatsApp ne partent plus. Cliquez sur « Connecter WhatsApp » pour rétablir l\'accès en une minute.'
                : 'Meta limite cet accès dans le temps. Cliquez sur « Connecter WhatsApp » et reprenez le même numéro : rien d\'autre ne change.',
            'href' => '/admin/whatsapp',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
