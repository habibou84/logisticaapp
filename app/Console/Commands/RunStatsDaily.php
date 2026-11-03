<?php

namespace App\Console\Commands;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Services\Reports\StatsAlerts;
use Illuminate\Console\Command;

class RunStatsDaily extends Command
{
    protected $signature = 'stats:daily {--snapshot : relevé du soir seulement} {--alerts : alertes seulement}';

    protected $description = 'Statistiques : relevé du soir (courses non traitées…) et alertes du matin (zones, livreurs, clients)';

    public function handle(StatsAlerts $stats): int
    {
        $companies = Company::query()->where('status', CompanyStatus::Active->value)->get();
        if (! $this->option('alerts')) {
            $companies->each(fn (Company $c) => $stats->snapshot($c));
            $this->info("Relevé enregistré pour {$companies->count()} entreprise(s).");
        }
        if (! $this->option('snapshot')) {
            $this->info($stats->sendAll().' alerte(s) envoyée(s).');
        }

        return self::SUCCESS;
    }
}
