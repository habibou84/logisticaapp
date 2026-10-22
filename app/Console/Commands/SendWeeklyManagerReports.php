<?php

namespace App\Console\Commands;

use App\Services\Reports\WeeklyManagerReport;
use Illuminate\Console\Command;

class SendWeeklyManagerReports extends Command
{
    protected $signature = 'reports:weekly';

    protected $description = 'Envoie le point de la semaine aux administrateurs des entreprises (lundi matin)';

    public function handle(WeeklyManagerReport $reports): int
    {
        $count = $reports->sendDue();
        $this->info("{$count} point(s) de la semaine envoyé(s).");

        return self::SUCCESS;
    }
}
