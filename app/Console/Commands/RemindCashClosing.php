<?php

namespace App\Console\Commands;

use App\Services\Finance\CashClosings;
use Illuminate\Console\Command;

class RemindCashClosing extends Command
{
    protected $signature = 'cash:close-reminder';

    protected $description = 'Rappelle aux responsables de la caisse de clôturer la journée, à l\'heure choisie par l\'entreprise';

    public function handle(CashClosings $closings): int
    {
        $this->info($closings->remindDue().' entreprise(s) rappelée(s).');

        return self::SUCCESS;
    }
}
