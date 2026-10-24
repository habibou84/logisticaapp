<?php

namespace App\Console\Commands;

use App\Services\Ratings\RatingAlerts;
use Illuminate\Console\Command;

class AlertCourierRatings extends Command
{
    protected $signature = 'couriers:rating-alerts';

    protected $description = 'Notation des livreurs : alerte les responsables (note sous le seuil, mauvaises notes répétées)';

    public function handle(RatingAlerts $alerts): int
    {
        $this->info($alerts->run().' alerte(s) envoyée(s).');

        return self::SUCCESS;
    }
}
