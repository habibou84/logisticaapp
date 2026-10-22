<?php

namespace App\Services\Orders;

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderAlert;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Courses qui attendent un livreur :
 *  - ramassage : course validée, personne n'est désigné pour aller la chercher ;
 *  - livraison : colis récupéré, à l'entrepôt ou reporté à aujourd'hui, sans livreur.
 * Le délai part de l'entrée dans le statut (ou, pour la livraison, du jour prévu s'il est plus
 * tard) ; au-delà du seuil de l'entreprise, la course est « en retard » et le dispatch
 * est alerté, puis les administrateurs après trois fois le seuil. Passé l'heure limite
 * du jour de l'entreprise, une alerte rappelle les courses du jour encore sans livreur.
 */
class AwaitingCourier
{
    public const PICKUP = 'pickup';

    public const DELIVERY = 'delivery';

    private const DELIVERY_STATUSES = [OrderStatus::PickedUp, OrderStatus::AtHub, OrderStatus::Rescheduled];

    public function stage(Order $order): ?string
    {
        return match (true) {
            $order->status === OrderStatus::Confirmed && $order->pickup_hub_id === null => self::PICKUP,
            in_array($order->status, self::DELIVERY_STATUSES, true) && ! $order->return_requested
                // Un report peut déjà avoir son livreur tout en restant « reporté »
                && ! ($order->status === OrderStatus::Rescheduled && $this->hasDeliveryCourier($order)) => self::DELIVERY,
            default => null,
        };
    }

    private function hasDeliveryCourier(Order $order): bool
    {
        return $order->assignments()->active()->where('type', AssignmentType::Delivery->value)->exists();
    }

    /**
     * Requête des courses qui attendent un livreur (avant le contrôle du jour prévu) :
     * file « Sans livreur » de la liste et alertes. $stage : pickup, delivery ou null (les deux).
     */
    public static function scope(Builder $query, ?string $stage = null, ?string $day = null): Builder
    {
        $day ??= today()->toDateString();

        return $query->where(function (Builder $q) use ($stage, $day) {
            if ($stage !== self::DELIVERY) {
                $q->orWhere(fn (Builder $q) => $q->where('status', OrderStatus::Confirmed->value)->whereNull('pickup_hub_id'));
            }
            if ($stage !== self::PICKUP) {
                $q->orWhere(fn (Builder $q) => $q->whereIn('status', OrderStatus::values(self::DELIVERY_STATUSES))
                    ->where('return_requested', false)
                    ->whereDoesntHave('assignments', fn ($a) => $a->active()->where('type', AssignmentType::Delivery->value))
                    // Livraison prévue un autre jour : pas encore en attente (le ramassage, lui, se fait dès la validation)
                    ->where(fn (Builder $q) => $q->whereNull('delivery_scheduled_date')->orWhereDate('delivery_scheduled_date', '<=', $day)));
            }
        });
    }

    /**
     * Début de l'attente ; null si la course n'attend pas encore (livraison prévue un autre jour).
     */
    public function since(Order $order): ?CarbonInterface
    {
        $since = $order->status_changed_at ?? $order->updated_at ?? now();

        // Livraison prévue plus tard : l'attente du livreur de livraison part de ce jour-là
        if ($this->stage($order) === self::DELIVERY && $order->delivery_scheduled_date !== null && $order->delivery_scheduled_date->copy()->startOfDay()->gt($since)) {
            $since = $order->delivery_scheduled_date->copy()->startOfDay();
        }

        return $since->isFuture() ? null : $since;
    }

    public function threshold(Company $company, string $stage): int
    {
        return (int) ($stage === self::PICKUP ? $company->pickup_assign_alert_minutes : $company->delivery_assign_alert_minutes);
    }

    /**
     * État affiché dans la liste des courses (null si la course n'attend pas de livreur).
     *
     * @return array{stage: string, since: string, minutes: int, late: bool, threshold_minutes: int, after_cutoff: bool, cutoff_time: ?string}|null
     */
    public function describe(Order $order, Company $company): ?array
    {
        $stage = $this->stage($order);
        $since = $stage ? $this->since($order) : null;
        if ($since === null) {
            return null;
        }

        $minutes = (int) $since->diffInMinutes(now());
        $threshold = $this->threshold($company, $stage);

        return [
            'stage' => $stage,
            'since' => $since->toIso8601String(),
            'minutes' => $minutes,
            'late' => $threshold > 0 && $minutes >= $threshold,
            'threshold_minutes' => $threshold,
            // Heure limite du jour passée : la course risque de ne pas partir aujourd'hui
            'after_cutoff' => $this->cutoffPassed($company),
            'cutoff_time' => $company->cutoffFor(),
        ];
    }

    /**
     * L'heure limite du jour de l'entreprise est-elle passée (heure locale) ?
     */
    public function cutoffPassed(Company $company): bool
    {
        if (! $company->cutoffFor()) {
            return false;
        }

        return now($company->timezone ?: config('app.timezone'))->format('H:i') >= $company->cutoffFor();
    }

    /**
     * Heure limite pour le tableau de bord : l'heure, si elle est passée, le nombre de courses du jour
     * encore sans livreur et, à part, les ramassages de courses à livrer un autre jour (null si
     * l'entreprise n'a pas d'heure limite).
     *
     * @return array{time: string, passed: bool, count: int, later: int}|null
     */
    public function cutoff(Company $company): ?array
    {
        if (! $company->cutoffFor()) {
            return null;
        }

        [$today, $later] = $this->splitByDeliveryDay($company, $this->waiting($company));

        return [
            'time' => $company->cutoffFor(),
            'passed' => $this->cutoffPassed($company),
            'count' => $today->count(),
            'later' => $later->count(),
        ];
    }

    /**
     * Courses en attente séparées entre celles à livrer aujourd'hui (ou sans date) et les ramassages de
     * courses prévues un autre jour : celles-ci ne risquent pas un retard de livraison.
     *
     * @param  Collection<int, array{order: Order}>  $waiting
     * @return array{0: Collection<int, array{order: Order}>, 1: Collection<int, array{order: Order}>}
     */
    private function splitByDeliveryDay(Company $company, Collection $waiting): array
    {
        $today = now($company->timezone ?: config('app.timezone'))->toDateString();

        return $waiting->partition(fn (array $w) => ! $w['order']->delivery_scheduled_date
            || $w['order']->delivery_scheduled_date->toDateString() <= $today)->map->values()->all();
    }

    /**
     * Courses qui attendent un livreur dans une entreprise, avec leur état.
     *
     * @return Collection<int, array{order: Order, stage: string, minutes: int, late: bool}>
     */
    public function waiting(Company $company): Collection
    {
        return self::scope(Order::withoutGlobalScopes()->where('company_id', $company->id))
            ->get()
            ->map(fn (Order $order) => ($state = $this->describe($order, $company)) ? ['order' => $order, ...$state] : null)
            ->filter()
            ->values();
    }

    /**
     * Compteurs du menu et des onglets : courses en retard, par étape.
     *
     * @return array{pickup: int, delivery: int, total: int}
     */
    public function lateCounts(Company $company): array
    {
        $late = $this->waiting($company)->where('late', true);
        $pickup = $late->where('stage', self::PICKUP)->count();
        $delivery = $late->where('stage', self::DELIVERY)->count();

        return ['pickup' => $pickup, 'delivery' => $delivery, 'total' => $pickup + $delivery];
    }

    /**
     * Encadré du tableau de bord : par étape, nombre de courses en attente, en retard,
     * et attente la plus longue (minutes).
     *
     * @return array<string, array{count: int, late: int, oldest_minutes: ?int, threshold_minutes: int}>
     */
    public function summary(Company $company): array
    {
        $waiting = $this->waiting($company);

        return collect([self::PICKUP, self::DELIVERY])->mapWithKeys(fn (string $stage) => [$stage => [
            'count' => $waiting->where('stage', $stage)->count(),
            'late' => $waiting->where('stage', $stage)->where('late', true)->count(),
            'oldest_minutes' => $waiting->where('stage', $stage)->max('minutes'),
            'threshold_minutes' => $this->threshold($company, $stage),
        ]])->all();
    }

    /**
     * Alertes : dispatch au-delà du seuil (une fois par course et par statut), puis
     * administrateurs au-delà de trois fois le seuil. Regroupées par étape.
     *
     * @return int nombre de notifications envoyées
     */
    public function alert(): int
    {
        $sent = $this->alertCutoff();

        Company::query()->where('status', 'active')
            ->where(fn ($q) => $q->where('pickup_assign_alert_minutes', '>', 0)->orWhere('delivery_assign_alert_minutes', '>', 0))
            ->each(function (Company $company) use (&$sent) {
                $late = $this->waiting($company)->where('late', true);

                foreach ([self::PICKUP, self::DELIVERY] as $stage) {
                    $threshold = $this->threshold($company, $stage);
                    $orders = $late->where('stage', $stage)->sortByDesc('minutes');

                    $first = $orders->filter(fn ($w) => $w['order']->unassigned_alerted_at === null);
                    if ($first->isNotEmpty()) {
                        $sent += $this->notify($company, $stage, $first, $threshold, [Role::Admin, Role::Dispatcher], false);
                        Order::withoutGlobalScopes()->whereKey($first->pluck('order.id'))->update(['unassigned_alerted_at' => now()]);
                    }

                    $escalate = $orders->filter(fn ($w) => $w['order']->unassigned_escalated_at === null && $w['minutes'] >= 3 * $threshold);
                    if ($escalate->isNotEmpty()) {
                        $sent += $this->notify($company, $stage, $escalate, 3 * $threshold, [Role::Admin], true);
                        Order::withoutGlobalScopes()->whereKey($escalate->pluck('order.id'))->update(['unassigned_escalated_at' => now()]);
                    }
                }
            });

        return $sent;
    }

    /**
     * Heure limite du jour passée : une alerte par jour aux administrateurs et au dispatch,
     * avec les courses encore sans livreur (ramassage et livraison).
     */
    private function alertCutoff(): int
    {
        $sent = 0;

        Company::query()->where('status', 'active')->where(fn ($q) => $q->whereNotNull('daily_cutoff_time')->orWhereNotNull('cutoff_by_day'))
            ->each(function (Company $company) use (&$sent) {
                $today = now($company->timezone ?: config('app.timezone'))->toDateString();
                if (! $this->cutoffPassed($company) || $company->cutoff_alerted_on?->toDateString() === $today) {
                    return;
                }
                $company->forceFill(['cutoff_alerted_on' => $today])->save();

                [$waiting, $later] = $this->splitByDeliveryDay($company, $this->waiting($company)->sortByDesc('minutes'));
                if ($waiting->isEmpty()) {
                    return;
                }

                $users = User::forCompany($company->id)->where('status', 'active')->role([Role::Admin->value, Role::Dispatcher->value])->get();
                if ($users->isEmpty()) {
                    return;
                }

                $pickup = $waiting->where('stage', self::PICKUP)->count();
                $delivery = $waiting->where('stage', self::DELIVERY)->count();
                $count = $waiting->count();
                $codes = $waiting->pluck('order.tracking_code')->take(5)->implode(', ').($count > 5 ? '…' : '');

                Notification::send($users, new OrderAlert(
                    $waiting->first()['order'],
                    'unassigned_cutoff',
                    "⏰ {$company->cutoffFor()} passées : ".($count > 1 ? "{$count} courses du jour" : '1 course du jour').' toujours sans livreur',
                    collect(["{$pickup} à ramasser" => $pickup, "{$delivery} à livrer" => $delivery])->filter()->keys()->implode(' · ')
                        ." : {$codes}. Elles risquent de ne pas être livrées aujourd'hui."
                        // Ramassages de courses prévues un autre jour : à faire, mais sans retard de livraison
                        .($later->isNotEmpty() ? ' Et '.$later->count().' à ramasser pour un autre jour.' : ''),
                    ['severity' => 'alert', 'count' => $count, 'href' => '/admin/courses?queue=unassigned'],
                ));
                $sent++;
            });

        return $sent;
    }

    /**
     * @param  Collection<int, array{order: Order, minutes: int}>  $orders
     * @param  list<Role>  $roles
     */
    private function notify(Company $company, string $stage, Collection $orders, int $minutes, array $roles, bool $escalation): int
    {
        $users = User::forCompany($company->id)->where('status', 'active')->role(array_map(fn (Role $r) => $r->value, $roles))->get();
        if ($users->isEmpty()) {
            return 0;
        }

        $count = $orders->count();
        $what = $stage === self::PICKUP ? 'de ramassage' : 'de livraison';
        $codes = $orders->pluck('order.tracking_code')->take(5)->implode(', ').($count > 5 ? '…' : '');
        $title = ($escalation ? '🚨 ' : '⏰ ').($count > 1 ? "{$count} courses" : "Course {$orders->first()['order']->tracking_code}")
            ." toujours sans livreur {$what}";

        Notification::send($users, new OrderAlert(
            $orders->first()['order'],
            'unassigned_'.$stage,
            $title,
            'En attente depuis plus de '.self::duration($minutes)." : {$codes}. Assignez un livreur.",
            ['severity' => 'alert', 'count' => $count, 'href' => '/admin/courses?queue='.($stage === self::PICKUP ? 'to_pickup' : 'to_deliver')],
        ));

        return 1;
    }

    public static function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} min";
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest ? "{$hours} h ".str_pad((string) $rest, 2, '0', STR_PAD_LEFT) : "{$hours} h";
    }
}
