<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Platform\CustomDomains;
use Illuminate\Console\Command;

class CheckCustomDomains extends Command
{
    protected $signature = 'domains:check';

    protected $description = 'Vérifie les domaines personnalisés en attente (DNS et HTTPS) et active ceux qui répondent';

    public function handle(CustomDomains $domains): int
    {
        $active = 0;

        // En attente depuis moins de 14 jours : au-delà, l'entreprise relance elle-même la vérification
        Company::query()->where('custom_domain_status', 'pending')->where('updated_at', '>', now()->subDays(14))
            ->each(function (Company $company) use ($domains, &$active) {
                if (($domains->check($company)['status'] ?? null) === 'active') {
                    $active++;
                }
            });

        $this->info("{$active} domaine(s) activé(s).");

        return self::SUCCESS;
    }
}
