<?php

namespace App\Notifications;

use App\Models\Merchant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Le marchand a ajouté ou changé un moyen de paiement : à vérifier avant de le payer avec.
 */
class MerchantPaymentMethodChanged extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  string  $action  added | updated | removed */
    public function __construct(public Merchant $merchant, public string $action, public string $summary) {}

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
        $removed = $this->action === 'removed';

        return [
            'kind' => 'merchant_payment_method',
            'title' => ($removed ? '💳 ' : '🔒 ').$this->merchant->business_name.' : moyen de paiement '.match ($this->action) {
                'added' => 'ajouté',
                'removed' => 'retiré',
                default => 'modifié',
            },
            'body' => $removed
                ? $this->summary.' ne sera plus proposé à la caisse.'
                : $this->summary.' — à vérifier auprès du client (à son numéro habituel) avant de le payer avec.',
            'severity' => $removed ? 'note' : 'alert',
            'merchant_id' => $this->merchant->id,
            'href' => '/admin/marchands?id='.$this->merchant->id.'&onglet=paiement',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
