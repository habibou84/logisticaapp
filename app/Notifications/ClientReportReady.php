<?php

namespace App\Notifications;

use App\Models\ClientReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Rapport du mois envoyé par l'équipe : cloche de l'espace client.
 */
class ClientReportReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ClientReport $report) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $data = $this->report->data;

        return [
            'kind' => 'client_report',
            'title' => "Votre rapport de {$data['label']}",
            'body' => "{$data['delivered']} courses livrées".($data['on_time_rate'] !== null ? ", {$data['on_time_rate']} % dans le délai" : '').'.',
            'href' => "/marchand/rapports/{$this->report->id}",
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
