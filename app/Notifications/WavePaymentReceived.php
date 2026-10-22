<?php

namespace App\Notifications;

use App\Models\PlatformWavePayment;
use App\Support\Money;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Cloche de la console : paiement Wave d'une facture à vérifier (annoncé, puis déclaré payé).
 */
class WavePaymentReceived extends Notification
{
    public function __construct(public PlatformWavePayment $payment, public bool $claimed) {}

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
            'kind' => 'wave_payment',
            'title' => ($this->claimed ? 'Paiement Wave déclaré payé : ' : 'Paiement Wave en cours : ').Money::format($this->payment->amount),
            'body' => ($this->payment->company?->name ?? 'Entreprise').' · facture '.($this->payment->invoice?->number ?? '').' · depuis le '.$this->payment->payer_phone.'.',
            'href' => '/console/paiements',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
