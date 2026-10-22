<?php

namespace App\Console\Commands;

use App\Services\Orders\AwaitingCourier;
use Illuminate\Console\Command;

class AlertUnassignedOrders extends Command
{
    protected $signature = 'orders:unassigned';

    protected $description = 'Alerte le dispatch des courses restées sans livreur (ramassage ou livraison) au-delà du délai de l\'entreprise';

    public function handle(AwaitingCourier $awaiting): int
    {
        $this->info($awaiting->alert().' alerte(s) envoyée(s).');

        return self::SUCCESS;
    }
}
