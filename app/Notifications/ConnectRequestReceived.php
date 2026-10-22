<?php

namespace App\Notifications;

use App\Models\ConnectRequest;
use App\Support\PhoneNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Un e-commerçant demande à devenir client (depuis l'annuaire ou Jibiat Marchand) : à valider par l'équipe.
 */
class ConnectRequestReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ConnectRequest $request) {}

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
            'kind' => 'connect_request',
            'title' => "Nouveau client potentiel : {$this->request->business_name}",
            'body' => trim("{$this->request->contact_name} · ".PhoneNumber::display($this->request->phone).' · demande à ouvrir un compte.'),
            'href' => '/admin/marchands?demandes=1',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
