<?php

namespace App\Notifications;

use App\Models\Company;
use App\Models\WhatsAppQrSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * WhatsApp des alertes déconnecté depuis quelques minutes : cloche et e-mail des administrateurs,
 * avec le lien vers les réglages des alertes pour scanner à nouveau le QR code.
 */
class AlertWhatsAppDisconnected extends Notification implements ShouldQueue
{
    use Queueable;

    public const HREF = '/admin/statistiques?onglet=integrations';

    public function __construct(public WhatsAppQrSession $session) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['database', 'broadcast', 'mail'] : ['database', 'broadcast'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'whatsapp_qr',
            'title' => 'WhatsApp des alertes déconnecté',
            'body' => 'Les alertes ne partent plus par ce numéro'.($this->session->phone ? " ({$this->session->phone})" : '')
                .'. Ouvrez le réglage des alertes et scannez à nouveau le QR code avec le téléphone.',
            'href' => self::HREF,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);
        $company = Company::find($this->session->company_id);

        return (new MailMessage)
            ->subject($data['title'])
            ->line($data['body'])
            ->line('En attendant, les alertes restent visibles dans la cloche de l\'application.')
            ->action('Scanner le QR code', $company ? $company->url(self::HREF) : url(self::HREF));
    }
}
