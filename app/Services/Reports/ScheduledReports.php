<?php

namespace App\Services\Reports;

use App\Enums\ReportFrequency;
use App\Enums\WhatsAppTemplate;
use App\Models\Order;
use App\Models\ScheduledReport;
use App\Services\Messaging\Messenger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Envoie les points d'activité programmés (quotidiens ou hebdomadaires) aux marchands.
 */
class ScheduledReports
{
    public function __construct(
        private readonly OrderSummary $summary,
        private readonly Messenger $messenger,
    ) {}

    /**
     * Envoie les rapports arrivés à échéance ; renvoie le nombre de messages mis en file.
     */
    public function sendDue(?CarbonInterface $now = null): int
    {
        $now = CarbonImmutable::instance($now ?? now());
        $sent = 0;

        ScheduledReport::withoutGlobalScopes()
            ->where('is_active', true)
            ->with(['merchant' => fn ($q) => $q->withoutGlobalScopes(), 'merchant.company'])
            ->each(function (ScheduledReport $report) use ($now, &$sent) {
                $merchant = $report->merchant;
                if ($merchant === null || ! $merchant->isActive() || ! $merchant->company?->isActive()) {
                    return;
                }

                $local = $now->setTimezone($merchant->company->timezone ?: config('app.timezone'));
                $dueAt = $local->setTimeFromTimeString($report->send_time);

                if (! $this->isDue($report, $local, $dueAt)) {
                    return;
                }

                [$from, $to, $label] = $this->period($report->frequency, $local);
                $data = $this->summary->build(Order::withoutGlobalScopes()->where('merchant_id', $merchant->id), $from, $to);

                // Rien à signaler : on n'envoie pas de rapport vide
                if ($data['counts']['total'] > 0) {
                    $this->messenger->merchantMessage($merchant, 'report.'.$report->frequency->value, WhatsAppTemplate::ActivityReport, [
                        $merchant->business_name,
                        $label,
                        $data['counts']['total'],
                        $data['counts']['delivered'],
                        $data['counts']['failed'] + $data['counts']['rescheduled'],
                        $data['counts']['returned'],
                        Money::format($data['amounts']['collected']),
                        Money::format($data['amounts']['fees']),
                        Money::format($data['amounts']['shipping_fees'] + $data['amounts']['other_fees']),
                        Money::format($data['amounts']['net_to_merchant']),
                    ]);
                    $sent++;
                }

                $report->forceFill(['last_sent_at' => $now])->save();
            });

        return $sent;
    }

    private function isDue(ScheduledReport $report, CarbonImmutable $local, CarbonImmutable $dueAt): bool
    {
        if ($local->lt($dueAt)) {
            return false;
        }

        if ($report->frequency === ReportFrequency::Weekly && $local->isoWeekday() !== $report->weekday) {
            return false;
        }

        return $report->last_sent_at === null || $report->last_sent_at->lt($dueAt);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function period(ReportFrequency $frequency, CarbonImmutable $local): array
    {
        $today = $local->startOfDay();

        if ($frequency === ReportFrequency::Daily) {
            return [$today, $today, 'du '.$today->format('d/m/Y')];
        }

        // Semaine écoulée : les 7 jours précédant le jour d'envoi
        $from = $today->subDays(7);
        $to = $today->subDay();

        return [$from, $to, 'du '.$from->format('d/m').' au '.$to->format('d/m/Y')];
    }
}
