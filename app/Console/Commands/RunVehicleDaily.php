<?php

namespace App\Console\Commands;

use App\Services\Vehicles\VehicleDaily;
use Illuminate\Console\Command;

/**
 * Tâche du matin des véhicules : rappels (papiers, entretien), location-vente, dotations, véhicules à rendre.
 */
class RunVehicleDaily extends Command
{
    protected $signature = 'vehicles:daily';

    protected $description = 'Véhicules : rappels des papiers et de l\'entretien, retenues de location-vente, dotations carburant';

    public function handle(VehicleDaily $daily): int
    {
        $done = $daily->run();
        $this->info(collect($done)->map(fn ($n, $k) => "{$k} : {$n}")->implode(' · '));

        return self::SUCCESS;
    }
}
