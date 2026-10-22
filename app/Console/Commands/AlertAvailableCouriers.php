<?php

namespace App\Console\Commands;

use App\Services\Couriers\CourierAvailability;
use Illuminate\Console\Command;

class AlertAvailableCouriers extends Command
{
    protected $signature = 'couriers:available';

    protected $description = 'Alerte le dispatch des livreurs libres ou bientôt libres qui ont des ramassages près d\'eux';

    public function handle(CourierAvailability $availability): int
    {
        $this->info($availability->alert().' alerte(s) envoyée(s).');

        return self::SUCCESS;
    }
}
