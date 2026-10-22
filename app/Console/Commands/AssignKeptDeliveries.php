<?php

namespace App\Console\Commands;

use App\Services\Couriers\PickupCustody;
use Illuminate\Console\Command;

class AssignKeptDeliveries extends Command
{
    protected $signature = 'parcels:kept-deliveries';

    protected $description = 'Jour de livraison venu : confie la livraison des colis gardés (mission ramassage) au livreur qui les a';

    public function handle(PickupCustody $custody): int
    {
        $this->info($custody->assignKeptDeliveries().' livraison(s) confiée(s).');

        return self::SUCCESS;
    }
}
