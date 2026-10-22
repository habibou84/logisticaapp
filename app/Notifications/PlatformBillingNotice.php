<?php

namespace App\Notifications;

use App\Models\PlatformInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Facture de la plateforme émise ou en retard : cloche et temps réel des administrateurs
 * de l'entreprise.
 */
class PlatformBillingNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public PlatformInvoice $invoice, public string $stage) {}

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
        $amount = number_format($this->invoice->balance(), 0, ',', ' ').' F';
        $due = $this->invoice->due_at->format('d/m/Y');
        $days = (int) config('platform.billing.restrict_after_days');

        [$title, $body] = match ($this->stage) {
            'issued' => ["Facture {$this->invoice->number} : {$amount}", "Abonnement du mois, à régler avant le {$due}."],
            'due' => ["Facture {$this->invoice->number} à régler aujourd'hui", "{$amount} à payer. Sans paiement, la création de courses sera suspendue {$days} jours après l'échéance."],
            'warning' => ["Facture {$this->invoice->number} en retard", "{$amount} à payer depuis le {$due}. La création de courses sera bientôt suspendue."],
            default => ['Création de courses suspendue', "Facture {$this->invoice->number} impayée ({$amount}). Tout reste consultable ; la création de courses reprend dès le paiement."],
        };

        return [
            'kind' => 'platform_billing',
            'title' => $title,
            'body' => $body,
            'invoice_id' => $this->invoice->id,
            'href' => '/admin/abonnement',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
