<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Finance\ClientInvoicing;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class InvoiceClients extends Command
{
    protected $signature = 'billing:invoice {--month= : mois à facturer (AAAA-MM), par défaut le mois précédent}';

    protected $description = 'Calcule les factures du mois des clients sous formule (distribution, MAD), en brouillon';

    public function handle(ClientInvoicing $invoicing): int
    {
        $option = $this->option('month');

        if ($option !== null && ! preg_match('/^\d{4}-\d{2}$/', $option)) {
            $this->error('Format attendu : AAAA-MM.');

            return self::INVALID;
        }

        $total = 0;
        Company::query()->where('status', 'active')->each(function (Company $company) use ($option, $invoicing, &$total) {
            $month = $option
                ? CarbonImmutable::createFromFormat('Y-m-d', $option.'-01')
                : CarbonImmutable::now($company->timezone ?: config('app.timezone'))->startOfMonth()->subMonth();
            $total += $invoicing->generate($company, $month);
        });

        $this->info("{$total} facture(s) calculée(s) en brouillon.");

        return self::SUCCESS;
    }
}
