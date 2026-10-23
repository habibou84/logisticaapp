<?php

namespace App\Console\Commands;

use App\Services\Orders\InternalDelays;
use Illuminate\Console\Command;

class WatchInternalDelays extends Command
{
    protected $signature = 'orders:internal-delays';

    protected $description = 'Délai interne de distribution : alerte l\'équipe des courses à risque (80 % du délai) et en retard';

    public function handle(InternalDelays $delays): int
    {
        $sent = $delays->watch();
        $this->info("{$sent['risk']} risque(s) de retard, {$sent['late']} retard(s) signalé(s).");

        return self::SUCCESS;
    }
}
