<?php

namespace App\Console\Commands;

use App\Services\Finance\ClientSpace;
use Illuminate\Console\Command;

class RemindClientInvoices extends Command
{
    protected $signature = 'billing:remind';

    protected $description = 'Rappelle aux clients leurs factures à payer : trois jours avant l\'échéance, puis en retard (chaque semaine)';

    public function handle(ClientSpace $space): int
    {
        $this->info($space->remind().' rappel(s) envoyé(s).');

        return self::SUCCESS;
    }
}
