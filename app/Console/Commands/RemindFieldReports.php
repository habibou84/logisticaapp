<?php

namespace App\Console\Commands;

use App\Services\Couriers\FieldReportReminders;
use Illuminate\Console\Command;

class RemindFieldReports extends Command
{
    protected $signature = 'field-reports:remind';

    protected $description = 'Relance le dispatch (puis les administrateurs) pour les problèmes des livreurs restés sans suite';

    public function handle(FieldReportReminders $reminders): int
    {
        $this->info($reminders->run().' relance(s) envoyée(s).');

        return self::SUCCESS;
    }
}
