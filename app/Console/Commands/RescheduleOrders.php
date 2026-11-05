<?php

namespace App\Console\Commands;

use App\Services\Orders\Reschedules;
use Illuminate\Console\Command;

class RescheduleOrders extends Command
{
    protected $signature = 'orders:reschedule';

    protected $description = 'Colis pas ramassés : message du soir aux clients, WhatsApp après Jibiat Marchand, rappel ou report automatique le matin';

    public function handle(Reschedules $reschedules): int
    {
        $stats = $reschedules->tick();
        $this->info("Soir : {$stats['evening']} demande(s) · WhatsApp : {$stats['whatsapp']} · rappels : {$stats['reminded']} · reports automatiques : {$stats['auto']}");

        return self::SUCCESS;
    }
}
