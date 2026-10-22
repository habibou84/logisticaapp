<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail au validateur : paiement Wave d'une facture annoncé ou déclaré payé, à vérifier puis confirmer.
 */
class WavePaymentToValidate extends Notification
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(private readonly array $details, private readonly ?string $mailer = null, private readonly ?string $from = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->details;
        if ($d['test'] ?? false) {
            return $this->message()->subject('Essai des alertes de paiement · Jibiat')
                ->greeting('Les alertes par e-mail fonctionnent.')
                ->line('Vous recevrez un e-mail comme celui-ci à chaque paiement Wave à vérifier.')
                ->action('Ouvrir la console', $d['console_url']);
        }

        return $this->message()
            ->subject(($d['claimed'] ? 'Paiement Wave déclaré payé' : 'Paiement Wave en cours')." : {$d['amount']} · {$d['number']}")
            ->greeting($d['claimed'] ? 'L\'entreprise indique avoir payé.' : 'Une entreprise va payer par Wave.')
            ->line("Montant : {$d['amount']}")
            ->line("Numéro Wave : {$d['payer']}")
            ->line("Entreprise : {$d['company']}")
            ->line("Facture : {$d['number']}")
            ->line('Vérifiez dans Wave que le montant est bien arrivé, puis confirmez.')
            ->action('Vérifier et confirmer', $d['validate_url'])
            ->line("Ou depuis la console : {$d['console_url']}");
    }

    private function message(): MailMessage
    {
        $message = new MailMessage;
        if ($this->mailer !== null) {
            $message->mailer($this->mailer);
        }
        if ($this->from !== null) {
            $message->from($this->from, 'Jibiat');
        }

        return $message;
    }
}
