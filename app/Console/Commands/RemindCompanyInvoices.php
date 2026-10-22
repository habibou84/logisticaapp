<?php

namespace App\Console\Commands;

use App\Services\Platform\Billing;
use Illuminate\Console\Command;

class RemindCompanyInvoices extends Command
{
    protected $signature = 'billing:remind';

    protected $description = 'Rappels des factures d\'abonnement à l\'échéance, en retard et avant restriction';

    public function handle(Billing $billing): int
    {
        $this->info($billing->remind().' rappel(s) envoyé(s).');

        return self::SUCCESS;
    }
}
