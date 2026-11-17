<?php

namespace App\Console\Commands;

use App\Services\Shifts\ShiftBoard;
use Illuminate\Console\Command;

class AlertMissingShifts extends Command
{
    protected $signature = 'shifts:missing';

    protected $description = 'Prévient le dispatch des livreurs qui n\'ont pas pointé à leur lieu de début';

    public function handle(ShiftBoard $board): int
    {
        $this->info($board->alert().' livreur(s) signalé(s).');

        return self::SUCCESS;
    }
}
