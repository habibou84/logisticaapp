<?php

namespace App\Notifications;

use App\Models\Merchant;
use App\Support\PhoneNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Nouvel e-commerçant inscrit en ligne : cloche et temps réel du back-office.
 */
class MerchantSignedUp extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Merchant $merchant) {}

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
            'kind' => 'merchant_signup',
            'title' => "Nouvel e-commerçant : {$this->merchant->business_name}",
            'body' => trim("{$this->merchant->contact_name} · ".PhoneNumber::display($this->merchant->phone).' · inscrit en ligne, compte actif.'),
            'merchant_id' => $this->merchant->id,
            'href' => '/admin/marchands?nouveaux=1',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
