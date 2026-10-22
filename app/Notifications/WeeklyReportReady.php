<?php

namespace App\Notifications;

use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Point de la semaine : cloche et temps réel des administrateurs (en plus du WhatsApp).
 */
class WeeklyReportReady extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $kpis
     * @param  array<string, mixed>  $previous
     */
    public function __construct(public string $period, public array $kpis, public array $previous, public int $toRelaunch) {}

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
        $rate = $this->kpis['success_rate'] === null ? '' : ' · taux de livraison '.str_replace('.', ',', (string) $this->kpis['success_rate']).' %';
        $relaunch = $this->toRelaunch ? " · {$this->toRelaunch} e-commerçant(s) à relancer" : '';

        return [
            'kind' => 'weekly_report',
            'title' => "Point de la semaine {$this->period}",
            'body' => "{$this->kpis['created']} courses créées, {$this->kpis['delivered']} livrées{$rate} · CA ".Money::format($this->kpis['revenue'] ?? 0).$relaunch.'.',
            'href' => '/admin/statistiques?periode=lastWeek',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
