<?php

namespace App\Services\Orders;

use App\Enums\Activity;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\BillingPlan;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderAlert;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;

/**
 * Délai interne de distribution : échéance de chaque course à partir de sa création (délai du contrat, sinon
 * celui de l'activité dans les réglages), puis alertes à l'équipe : « risque de retard » à 80 % du délai,
 * « en retard » à l'échéance, une seule fois chacune. Le client ne voit pas ce délai.
 */
class InternalDelays
{
    /** Part du délai écoulée au-delà de laquelle la course risque d'être en retard */
    public const RISK_SHARE = 0.8;

    /**
     * Délai (heures) d'une course : celui de son contrat, sinon celui de son activité, sinon aucun.
     */
    public function hours(Company $company, Activity $activity, ?int $billingPlanId): ?int
    {
        $plan = $billingPlanId ? BillingPlan::withoutGlobalScopes()->whereKey($billingPlanId)->value('internal_delay_hours') : null;
        $hours = $plan ?? ($company->internal_delays[$activity->value] ?? null);

        return $hours ? (int) $hours : null;
    }

    public function dueAt(Company $company, Activity $activity, ?int $billingPlanId, CarbonInterface $from): ?CarbonImmutable
    {
        $hours = $this->hours($company, $activity, $billingPlanId);

        return $hours ? CarbonImmutable::instance($from)->addHours($hours) : null;
    }

    /**
     * Alertes du moment, pour toutes les courses en cours qui ont un délai interne.
     *
     * @return array{risk: int, late: int}
     */
    public function watch(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $sent = ['risk' => 0, 'late' => 0];
        $final = array_map(fn (OrderStatus $s) => $s->value, array_filter(OrderStatus::cases(), fn (OrderStatus $s) => $s->isFinal()));

        Order::withoutGlobalScopes()->with(['merchant:id,business_name', 'company'])
            ->whereNull('deleted_at')
            ->whereNotNull('internal_due_at')
            ->where('internal_alert', '<', 2)
            ->whereNotIn('status', $final)
            ->orderBy('id')
            ->chunkById(200, function ($orders) use ($now, &$sent) {
                foreach ($orders as $order) {
                    $due = CarbonImmutable::instance($order->internal_due_at);
                    $start = CarbonImmutable::instance($order->created_at);
                    $riskAt = $start->addSeconds((int) round($start->diffInSeconds($due) * self::RISK_SHARE));

                    if ($now->greaterThanOrEqualTo($due)) {
                        $this->alert($order, 'internal_late', "⏰ En retard : {$order->tracking_code}",
                            "{$order->merchant?->business_name} · délai interne dépassé depuis le ".$due->setTimezone($this->tz($order))->format('d/m à H:i').'.');
                        $order->forceFill(['internal_alert' => 2])->saveQuietly();
                        $sent['late']++;
                    } elseif ($order->internal_alert < 1 && $now->greaterThanOrEqualTo($riskAt)) {
                        $left = $now->diffForHumans($due, ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2, 'locale' => 'fr']);
                        $this->alert($order, 'internal_risk', "⚠️ Risque de retard : {$order->tracking_code}",
                            "{$order->merchant?->business_name} · reste {$left} avant le délai interne (".$due->setTimezone($this->tz($order))->format('d/m à H:i').').');
                        $order->forceFill(['internal_alert' => 1])->saveQuietly();
                        $sent['risk']++;
                    }
                }
            });

        return $sent;
    }

    private function alert(Order $order, string $kind, string $title, string $body): void
    {
        // Dispatch et responsables : chacun selon son périmètre d'activités (OrderAlert)
        $users = User::withoutGlobalScopes()->where('company_id', $order->company_id)->where('status', 'active')
            ->role([Role::Admin->value, Role::ActivityManager->value, Role::Dispatcher->value])->get();

        Notification::send($users, new OrderAlert($order, $kind, $title, $body, [
            'severity' => $kind === 'internal_late' ? 'alert' : 'warning',
            'href' => "/admin/courses/{$order->id}",
        ]));
    }

    private function tz(Order $order): string
    {
        return $order->company?->timezone ?: config('app.timezone');
    }
}
