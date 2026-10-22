<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Domaine personnalisé vérifié : cloche des administrateurs de l'entreprise.
 */
class CustomDomainActivated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Company $company) {}

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
            'kind' => 'custom_domain',
            'title' => "Votre adresse {$this->company->custom_domain} est active",
            'body' => 'Vos marchands, livreurs et destinataires peuvent l\'utiliser. Les liens envoyés par WhatsApp et SMS l\'utilisent désormais.',
            'href' => '/admin/parametres',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
