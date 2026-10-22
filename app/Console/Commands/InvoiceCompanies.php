<?php

namespace App\Console\Commands;

use App\Services\Platform\Billing;
use Illuminate\Console\Command;

class InvoiceCompanies extends Command
{
    protected $signature = 'billing:invoice {--month= : mois à facturer (AAAA-MM), par défaut le mois précédent}';

    protected $description = 'Factures d\'abonnement du mois des entreprises qui ont une formule (une seule par mois)';

    public function handle(Billing $billing): int
    {
        $period = $this->option('month') ?: now()->subMonthNoOverflow()->format('Y-m');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $this->error('Mois invalide : utilisez AAAA-MM.');

            return self::FAILURE;
        }

        $created = $billing->generate($period);
        $this->info("{$created->count()} facture(s) créée(s) pour {$period}.");

        return self::SUCCESS;
    }
}
