<?php

namespace App\Console\Commands;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Services\Reports\ClientReports;
use App\Services\Reports\StatsMailer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendStatsEmails extends Command
{
    protected $signature = 'stats:email {frequency : weekly ou monthly}';

    protected $description = 'Statistiques : point par e-mail (semaine ou mois) ; le 1er du mois, brouillons des rapports des clients sous contrat';

    public function handle(StatsMailer $mailer, ClientReports $reports): int
    {
        $frequency = $this->argument('frequency') === 'monthly' ? 'monthly' : 'weekly';
        $this->info($mailer->sendAll($frequency).' e-mail(s) envoyé(s).');
        if ($frequency === 'monthly') {
            $count = Company::query()->where('status', CompanyStatus::Active->value)->get()
                ->sum(fn (Company $c) => $reports->generate($c, CarbonImmutable::now($c->timezone ?: config('app.timezone'))->subMonthNoOverflow()->startOfMonth()));
            $this->info("{$count} rapport(s) client à relire.");
        }

        return self::SUCCESS;
    }
}
