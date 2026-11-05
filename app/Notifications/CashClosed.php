<?php

namespace App\Notifications;

use App\Models\CashClosing;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Caisse clôturée (résumé aux administrateurs) ou, sans clôture, rappel du soir de la clôturer.
 */
class CashClosed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ?CashClosing $closing, public int $in = 0, public int $out = 0) {}

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
        if ($this->closing === null) {
            return [
                'kind' => 'cash_close_reminder',
                'title' => '🔒 Caisse pas encore clôturée',
                'body' => 'Entré : '.Money::format($this->in).', sorti : '.Money::format($this->out).'. Comptez la caisse et clôturez la journée.',
                'severity' => 'alert',
                'href' => '/admin/caisse/cloture',
            ];
        }

        $closing = $this->closing;

        return [
            'kind' => 'cash_closed',
            'title' => '🔒 Caisse du '.$closing->day->format('d/m').' clôturée',
            'body' => 'Entré : '.Money::format($closing->total_in).', sorti : '.Money::format($closing->total_out).'. '
                .($closing->explanation ? 'Écart : '.Money::format($closing->difference).' (« '.$closing->explanation.' »).' : 'Aucun écart.'),
            'severity' => $closing->explanation ? 'alert' : 'note',
            'closing_id' => $closing->id,
            'href' => '/admin/caisse/cloture',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
