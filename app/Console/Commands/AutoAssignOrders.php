<?php

namespace App\Console\Commands;

use App\Services\Assignment\AutoAssigner;
use Illuminate\Console\Command;

class AutoAssignOrders extends Command
{
    protected $signature = 'assignments:auto';

    protected $description = 'Rattrapage de l\'assignation automatique : ramassages encore sans livreur confiés selon le planning';

    public function handle(AutoAssigner $assigner): int
    {
        $this->info($assigner->run().' ramassage(s) confié(s).');

        return self::SUCCESS;
    }
}
