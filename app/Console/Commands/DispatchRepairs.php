<?php

namespace App\Console\Commands;

use App\Services\Workshop\RepairDispatcher;
use Illuminate\Console\Command;

class DispatchRepairs extends Command
{
    protected $signature = 'repairs:dispatch';

    protected $description = 'Passe au mécanicien suivant les pannes restées sans réponse et propose les pannes sans mécanicien';

    public function handle(RepairDispatcher $dispatcher): int
    {
        $result = $dispatcher->tick();
        $this->info("{$result['expired']} proposition(s) expirée(s), {$result['offered']} panne(s) proposée(s).");

        return self::SUCCESS;
    }
}
