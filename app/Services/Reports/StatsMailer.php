<?php

namespace App\Services\Reports;

use App\Enums\CompanyStatus;
use App\Mail\StatsReportMail;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * Point par e-mail (Statistiques › Analyse et partage › Partage et alertes) : la semaine écoulée chaque lundi, le mois écoulé le 1er,
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

    /**
     * Modèles d'export à envoyer à date fixe (chaque lundi ou le 1er du mois), aux adresses du point par e-mail :
     * le même export, sur la semaine ou le mois écoulé.
     *
     * @param  'weekly'|'monthly'  $frequency
     */
    public function sendTemplates(string $frequency, ?CarbonImmutable $now = null): int
    {
        $sent = 0;
        foreach (Company::query()->where('status', CompanyStatus::Active->value)->get() as $company) {
            $settings = $this->settings->for($company);
            $recipients = $settings['email']['recipients'];
            $moment = $now ?? CarbonImmutable::now($company->timezone ?: config('app.timezone'));
            [$from, $to] = $frequency === 'monthly'
                ? [$moment->subMonthNoOverflow()->startOfMonth(), $moment->subMonthNoOverflow()->endOfMonth()->startOfDay()]
                : [$moment->startOfWeek()->subWeek(), $moment->startOfWeek()->subDay()];
            $from = CarbonImmutable::parse($from->toDateString());
            $to = CarbonImmutable::parse($to->toDateString());
            foreach (collect($settings['exports'])->where('schedule', $frequency) as $template) {
                if ($recipients === []) {
                    continue;
                }
                $path = $this->export->file($company, $from, $to, [...$template, 'amounts' => ! $template['hide_amounts']]);
                try {
                    Mail::to($recipients)->send(new StatsReportMail($company, $template['name'], $this->export->summary($company, $from, $to, ! $template['hide_amounts']),
                        $path, "statistiques-{$from->toDateString()}-au-{$to->toDateString()}.{$template['format']}"));
                    $sent++;
                } finally {
                    @unlink($path);
                }
            }
        }

        return $sent;
    }
}
