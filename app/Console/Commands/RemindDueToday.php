<?php

namespace App\Console\Commands;

use App\Services\Orders\DueTodayReminder;
use Illuminate\Console\Command;

class RemindDueToday extends Command
{
    protected $signature = 'orders:due-today';

    protected $description = 'Rappelle au dispatch les courses prévues à livrer aujourd\'hui (reports et colis ramassés en avance)';

    public function handle(DueTodayReminder $reminder): int
    {
        $this->info($reminder->run().' entreprise(s) prévenue(s).');

        return self::SUCCESS;
    }
}
