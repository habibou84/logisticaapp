<?php

namespace App\Console\Commands;

use App\Services\Assignment\PlanningDigest;
use Illuminate\Console\Command;

class SendPlanningDigest extends Command
{
    protected $signature = 'couriers:planning-digest';

    protected $description = 'Le matin : chaque livreur reçoit ce qu\'il couvre aujourd\'hui selon le planning';

    public function handle(PlanningDigest $digest): int
    {
        $this->info($digest->run().' livreur(s) prévenu(s).');

        return self::SUCCESS;
    }
}
