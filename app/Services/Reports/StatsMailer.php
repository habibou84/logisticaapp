<?php

namespace App\Services\Reports;

use App\Enums\CompanyStatus;
use App\Mail\StatsReportMail;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * Point par e-mail (Statistiques › Objectifs › Intégrations) : la semaine écoulée chaque lundi, le mois écoulé le 1er,
 * aux adresses choisies, avec le fichier Excel complet.
 */
class StatsMailer
{
    public function __construct(private readonly StatsSettings $settings, private readonly StatsExport $export) {}

    /** @param 'weekly'|'monthly' $frequency */
    public function sendAll(string $frequency, ?CarbonImmutable $now = null): int
    {
        return Company::query()->where('status', CompanyStatus::Active->value)->get()
            ->filter(fn (Company $c) => $this->settings->for($c)['email'][$frequency] && $this->settings->for($c)['email']['recipients'] !== [])
            ->sum(fn (Company $c) => $this->send($c, $frequency, $now) ? 1 : 0);
    }

    /** @param 'weekly'|'monthly' $frequency */
    public function send(Company $company, string $frequency, ?CarbonImmutable $now = null, ?array $recipients = null): bool
    {
        $recipients ??= $this->settings->for($company)['email']['recipients'];
        if ($recipients === []) {
            return false;
        }
        $now ??= CarbonImmutable::now($company->timezone ?: config('app.timezone'));
        [$from, $to, $title, $file] = $frequency === 'monthly'
            ? [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()->startOfDay(), 'Point du mois de '.$now->subMonthNoOverflow()->locale('fr')->isoFormat('MMMM YYYY'), 'statistiques-'.$now->subMonthNoOverflow()->format('Y-m').'.xlsx']
            : [$now->startOfWeek()->subWeek(), $now->startOfWeek()->subDay(), 'Point de la semaine', 'statistiques-semaine-'.$now->startOfWeek()->subWeek()->format('Y-m-d').'.xlsx'];

        $path = $this->export->xlsx($company, $from, $to, true);
        try {
            Mail::to($recipients)->send(new StatsReportMail($company, $title, $this->export->summary($company, $from, $to), $path, $file));
        } finally {
            @unlink($path);
        }

        return true;
    }
}
