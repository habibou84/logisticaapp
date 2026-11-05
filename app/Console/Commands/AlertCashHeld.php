<?php

namespace App\Console\Commands;

use App\Services\Finance\CashAlerts;
use Illuminate\Console\Command;

class AlertCashHeld extends Command
{
    protected $signature = 'cash:held';

    protected $description = 'Alerte la caisse (et rappelle le livreur) quand un livreur garde l\'argent trop longtemps ou au-delà du plafond';

    public function handle(CashAlerts $alerts): int
    {
        $this->info($alerts->run().' livreur(s) signalé(s).');

        return self::SUCCESS;
    }
}
