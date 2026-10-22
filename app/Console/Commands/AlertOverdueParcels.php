<?php

namespace App\Console\Commands;

use App\Services\Couriers\OverdueParcels;
use Illuminate\Console\Command;

class AlertOverdueParcels extends Command
{
    protected $signature = 'parcels:overdue';

    protected $description = 'Alerte le dispatch des colis restés chez un livreur au-delà du délai de l\'entreprise';

    public function handle(OverdueParcels $parcels): int
    {
        $this->info($parcels->run().' alerte(s) envoyée(s).');

        return self::SUCCESS;
    }
}
