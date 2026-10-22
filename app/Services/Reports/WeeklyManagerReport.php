<?php

namespace App\Services\Reports;

use App\Enums\CompanyStatus;
use App\Enums\MerchantStatus;
use App\Enums\Role;
use App\Enums\WhatsAppTemplate;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Notifications\WeeklyReportReady;
use App\Services\Messaging\Messenger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;

/**
 * Point de la semaine écoulée (lundi → dimanche) envoyé aux administrateurs de chaque
 * entreprise le lundi matin : WhatsApp (numéro de l'entreprise) et cloche du back-office.
 */
class WeeklyManagerReport
{
    // Heure d'envoi le lundi (heure de l'entreprise)
    public const SEND_AT = '07:30';

    public function __construct(
        private readonly Statistics $statistics,
        private readonly Messenger $messenger,
    ) {}

    /**
     * Envoie les points dus ; renvoie le nombre d'entreprises traitées.
     */
    public function sendDue(?CarbonInterface $now = null): int
    {
        $now = CarbonImmutable::instance($now ?? now());
        $sent = 0;

        Company::query()->where('status', CompanyStatus::Active->value)->where('weekly_report', true)
            ->each(function (Company $company) use ($now, &$sent) {
                $local = $now->setTimezone($company->timezone ?: config('app.timezone'));
                $monday = $local->startOfWeek(CarbonInterface::MONDAY);

                // Le lundi à partir de 7 h 30 (ou plus tard dans la semaine si l'envoi a été manqué), une fois par semaine
                if ($local->lt($monday->setTimeFromTimeString(self::SEND_AT))
                    || ($company->weekly_report_sent_at && $company->weekly_report_sent_at->greaterThanOrEqualTo($monday))) {
                    return;
                }

                if ($this->send($company, $monday->subWeek(), $monday->subDay())) {
                    $sent++;
                }
                $company->forceFill(['weekly_report_sent_at' => $now])->save();
            });

        return $sent;
    }

    /**
     * Point d'une semaine donnée ; faux si l'entreprise n'a eu aucune activité.
     */
    public function send(Company $company, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        $overview = $this->statistics->for($company)->overview($from, $to, true);
        $kpis = $overview['kpis'];
        $previous = $overview['previous_kpis'];

        if ($kpis['created'] === 0 && $kpis['delivered'] === 0) {
            return false;
        }

        $toRelaunch = $this->merchantsToRelaunch($company);
        $period = 'du '.$from->format('d/m').' au '.$to->format('d/m');
        $statsUrl = $company->url('/admin/statistiques?periode=lastWeek');

        $admins = User::query()->where('company_id', $company->id)->whereNull('merchant_id')
            ->where('status', 'active')->role(Role::Admin->value)->get();

        foreach ($admins as $admin) {
            $this->messenger->whatsapp($company->id, $admin->phone, 'staff', WhatsAppTemplate::ManagerReport, [
                strtok($admin->name, ' ') ?: $admin->name,
                $period,
                $company->name,
                $kpis['created'],
                self::trend($kpis['created'], $previous['created']),
                $kpis['delivered'],
                $kpis['success_rate'] === null ? '—' : str_replace('.', ',', (string) $kpis['success_rate']).' %',
                Money::format($kpis['revenue']),
                self::trend($kpis['revenue'], $previous['revenue']),
                $toRelaunch,
                $statsUrl,
            ], ['event' => 'report.manager']);
        }

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new WeeklyReportReady($period, $kpis, $previous, $toRelaunch));
        }

        return true;
    }

    /**
     * E-commerçants actifs qui n'ont plus créé de course depuis INACTIVE_DAYS jours.
     */
    private function merchantsToRelaunch(Company $company): int
    {
        $recent = Order::forCompany($company->id)->where('created_at', '>=', now()->subDays(Statistics::INACTIVE_DAYS))->select('merchant_id');

        return Merchant::forCompany($company->id)->where('status', MerchantStatus::Active->value)
            ->whereIn('id', Order::forCompany($company->id)->select('merchant_id'))
            ->whereNotIn('id', $recent)
            ->count();
    }

    /**
     * « +12 % » / « -5 % » / « stable » / « nouveau » par rapport à la semaine d'avant.
     */
    public static function trend(?int $value, ?int $previous): string
    {
        if (! $previous) {
            return $value ? 'nouveau' : 'stable';
        }

        $change = (int) round(($value - $previous) / $previous * 100);

        return match (true) {
            $change > 0 => "+{$change} % vs semaine précédente",
            $change < 0 => "{$change} % vs semaine précédente",
            default => 'stable',
        };
    }
}
