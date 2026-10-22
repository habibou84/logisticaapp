<?php

namespace App\Console\Commands;

use App\Services\Reports\ScheduledReports;
use Illuminate\Console\Command;

class SendScheduledReports extends Command
{
    protected $signature = 'reports:send';

    protected $description = 'Envoie sur WhatsApp les points d\'activité programmés arrivés à échéance';

    public function handle(ScheduledReports $reports): int
    {
        $count = $reports->sendDue();
        $this->info("{$count} rapport(s) envoyé(s).");

        return self::SUCCESS;
    }
}
