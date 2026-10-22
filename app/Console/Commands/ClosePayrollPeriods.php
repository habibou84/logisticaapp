<?php

namespace App\Console\Commands;

use App\Models\Courier;
use App\Services\Finance\CourierPay;
use App\Services\Finance\CourierPayroll;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ClosePayrollPeriods extends Command
{
    protected $signature = 'payroll:close {--date= : date de référence (AAAA-MM-JJ), par défaut hier}';

    protected $description = 'Prépare les fiches de paie des livreurs dont la période de paie est terminée';

    public function handle(CourierPay $pay, CourierPayroll $payroll): int
    {
        $option = $this->option('date');
        if ($option !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $option)) {
            $this->error('Format attendu : AAAA-MM-JJ.');

            return self::INVALID;
        }
        $date = $option ? Carbon::parse($option) : today()->subDay();

        $count = 0;
        Courier::query()->withoutGlobalScopes()
            ->whereHas('user', fn ($q) => $q->withoutGlobalScopes()->where('status', 'active'))
            ->each(function (Courier $courier) use ($pay, $payroll, $date, &$count) {
                $plan = $pay->planFor($courier);
                if (! $plan?->pay_period) {
                    return;
                }

                // Dernière période terminée à la date de référence (rattrape un jour manqué)
                [$start, $end] = $plan->periodContaining($date);
                if ($end->greaterThan($date)) {
                    [$start, $end] = $plan->periodContaining($start->subDay());
                }

                if ($payroll->closePeriod($courier, $plan, $start, $end)) {
                    $count++;
                }
            });

        $this->info("{$count} fiche(s) de paie préparée(s).");

        return self::SUCCESS;
    }
}
