<?php

namespace App\Console\Commands;

use App\Services\Stock\StorageBilling;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BillStorage extends Command
{
    protected $signature = 'storage:bill {--month= : mois à facturer (AAAA-MM), par défaut le mois précédent}';

    protected $description = 'Facture le stockage des produits en entrepôt (une écriture par contrat et par mois)';

    public function handle(StorageBilling $billing): int
    {
        $option = $this->option('month');

        if ($option !== null && ! preg_match('/^\d{4}-\d{2}$/', $option)) {
            $this->error('Format attendu : AAAA-MM.');

            return self::INVALID;
        }

        $month = $option ? Carbon::createFromFormat('Y-m-d', $option.'-01') : now()->subMonthNoOverflow();

        $count = $billing->bill($month);
        $this->info("{$count} contrat(s) facturé(s) pour {$month->format('m/Y')}.");

        return self::SUCCESS;
    }
}
