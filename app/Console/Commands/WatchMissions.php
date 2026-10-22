<?php

namespace App\Console\Commands;

use App\Services\Assignment\MissionWatch;
use Illuminate\Console\Command;

class WatchMissions extends Command
{
    protected $signature = 'assignments:watch';

    protected $description = 'Livreur injoignable (ramassages passés au remplaçant) et ramassages pas commencés avant l\'heure limite : alerte à l\'équipe';

    public function handle(MissionWatch $watch): int
    {
        $this->info($watch->run().' alerte(s) envoyée(s).');

        return self::SUCCESS;
    }
}
