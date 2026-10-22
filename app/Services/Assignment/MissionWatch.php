<?php

namespace App\Services\Assignment;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Company;
use App\Models\OrderAssignment;
use App\Models\User;
use App\Notifications\OrderAlert;
use App\Services\Orders\AwaitingCourier;
use App\Services\Orders\OrderDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sécurité des missions pas encore commencées (chaque minute) :
 *
 * - livreur injoignable : aucun signe de son application depuis le délai de l'entreprise (20 min par défaut) alors
 *   qu'une mission l'attend depuis aussi longtemps. Ses ramassages du planning (et livraisons automatiques) passent à un autre (sauf
 *   automatisation suspendue) ; l'équipe est prévenue une fois, avec ce qui reste à confier ;
 * - ramassage pas commencé à l'approche de l'heure limite du jour (une heure avant par défaut) : alerte à l'équipe,
 *   une fois par mission. Rien n'est retiré au livreur pour un simple silence.
 */
class MissionWatch
{
    /** Une mission toute neuve n'est pas « en retard » : délai minimal avant l'alerte de l'heure limite */
    private const LATE_GRACE_MINUTES = 15;

    public function __construct(
        private readonly OrderDispatcher $dispatcher,
        private readonly AutoAssigner $auto,
    ) {}

    public function run(): int
    {
        $sent = 0;

        Company::query()->where('status', 'active')->each(function (Company $company) use (&$sent) {
            $sent += $this->unreachable($company) + $this->latePickups($company);
        });

        return $sent;
    }

    private function unreachable(Company $company): int
    {
        $minutes = (int) $company->unreachable_minutes;
        if ($minutes <= 0) {
            return 0;
        }
        $limit = now()->subMinutes($minutes);

        $missions = $this->notStarted($company)
            ->whereNull('unreachable_at')
            ->where('assigned_at', '<=', $limit)
            ->whereHas('courier', fn (Builder $q) => $q
                ->where(fn ($s) => $s->whereNull('last_seen_at')->orWhere('last_seen_at', '<=', $limit))
                ->where(fn ($s) => $s->whereNull('last_location_at')->orWhere('last_location_at', '<=', $limit)))
            ->with(['courier.user', 'order'])
            ->get();

        $sent = 0;
        foreach ($missions->groupBy('courier_id') as $list) {
            OrderAssignment::query()->whereKey($list->pluck('id'))->update(['unreachable_at' => now()]);
            $courier = $list->first()->courier;
            $moved = $this->handOver($company, $list, $courier->user->name, $minutes);

            $kept = $list->reject(fn (OrderAssignment $a) => isset($moved[$a->id]));
            $codes = fn (Collection $c) => $c->pluck('order.tracking_code')->take(5)->implode(', ').($c->count() > 5 ? '…' : '');
            $body = ($list->count() > 1 ? "{$list->count()} missions pas commencées" : '1 mission pas commencée').' : '.$codes($list).'.';
            if ($moved) {
                $body .= ' Confiées à un autre livreur : '.collect($moved)->countBy()->map(fn ($n, $who) => "{$who} ({$n})")->implode(', ').'.';
            }
            if ($kept->isNotEmpty()) {
                $body .= ' Appelez-le, ou confiez '.($kept->count() > 1 ? 'les autres' : 'la course').' à un autre livreur.';
            }

            $sent += $this->tell($company, $list->first()->order, 'courier_unreachable',
                "📵 {$courier->user->name} injoignable depuis plus de ".AwaitingCourier::duration($minutes), $body, '/admin/carte');
        }

        return $sent;
    }

    /**
     * Ramassages du planning d'un livreur injoignable : retirés et confiés au remplaçant, s'il y en a un.
     *
     * @param  Collection<int, OrderAssignment>  $list
     * @return array<int, string> nom du nouveau livreur, par mission rendue
     */
    private function handOver(Company $company, Collection $list, string $name, int $minutes): array
    {
        $actor = User::forCompany($company->id)->where('status', 'active')->role(Role::Admin->value)->first();
        if ($company->automationPaused() || $actor === null) {
            return [];
        }

        $moved = [];
        foreach ($list as $assignment) {
            // Missions confiées par l'automatisme seulement : ramassages du planning, livraisons automatiques
            $pickup = $assignment->type === AssignmentType::Pickup && $assignment->source === AutoAssigner::SOURCE_PLANNING && $company->auto_pickup === Company::AUTO_ON;
            $delivery = $assignment->type === AssignmentType::Delivery && $assignment->source !== null && $company->auto_delivery === Company::AUTO_ON;
            if (! $pickup && ! $delivery) {
                continue;
            }
            try {
                $this->dispatcher->unassign($actor, $assignment->order, $assignment->type, "Livreur injoignable depuis plus de {$minutes} min");
                $next = $pickup
                    ? $this->auto->pickup($assignment->order, "{$name} injoignable")
                    : $this->auto->delivery($assignment->order, "{$name} injoignable");
            } catch (Throwable $e) {
                report($e);

                continue;
            }
            if ($next) {
                $moved[$assignment->id] = $next->courier->user->name;
            }
        }

        return $moved;
    }

    private function latePickups(Company $company): int
    {
        $before = (int) $company->late_pickup_alert_minutes;
        if (! $company->cutoffFor() || $before <= 0) {
            return 0;
        }

        $now = CarbonImmutable::now($company->timezone ?: config('app.timezone'));
        $cutoff = CarbonImmutable::parse($now->toDateString().' '.$company->cutoffFor(), $now->timezone);
        if ($now->lt($cutoff->subMinutes($before))) {
            return 0;
        }

        $missions = $this->notStarted($company)
            ->where('type', AssignmentType::Pickup->value)
            ->whereNull('late_alerted_at')
            ->where('assigned_at', '<=', now()->subMinutes(self::LATE_GRACE_MINUTES))
            ->whereHas('order', fn (Builder $q) => $q->withoutGlobalScopes()->dueBy($now->toDateString()))
            ->with(['courier.user', 'order'])
            ->get();
        if ($missions->isEmpty()) {
            return 0;
        }
        OrderAssignment::query()->whereKey($missions->pluck('id'))->update(['late_alerted_at' => now()]);

        $count = $missions->count();
        $byCourier = $missions->groupBy('courier_id')->map(fn (Collection $c) => $c->first()->courier->user->name.' : '.$c->count()
            .' ('.$c->pluck('order.tracking_code')->take(3)->implode(', ').($c->count() > 3 ? '…' : '').')')->implode(' ; ');

        return $this->tell($company, $missions->first()->order, 'late_pickup',
            '⏰ '.($count > 1 ? "{$count} ramassages pas commencés" : '1 ramassage pas commencé')." (heure limite {$company->cutoffFor()})",
            "{$byCourier}. Appelez les livreurs, ou confiez ces courses à un autre pour qu'elles soient livrées aujourd'hui.",
            '/admin/courses?queue=to_pickup');
    }

    /**
     * Missions acceptées ou à accepter, pas encore commencées, de l'entreprise.
     *
     * @return Builder<OrderAssignment>
     */
    private function notStarted(Company $company): Builder
    {
        return OrderAssignment::query()
            ->whereIn('status', [AssignmentStatus::Assigned->value, AssignmentStatus::Accepted->value])
            ->whereHas('courier', fn (Builder $q) => $q->withoutGlobalScopes()->where('company_id', $company->id))
            ->whereHas('order', fn (Builder $q) => $q->withoutGlobalScopes()->whereNotIn('status', [
                OrderStatus::PickupInProgress->value, OrderStatus::OutForDelivery->value, OrderStatus::Returning->value,
            ]));
    }

    private function tell(Company $company, $order, string $kind, string $title, string $body, string $href): int
    {
        $users = User::forCompany($company->id)->where('status', 'active')->role([Role::Admin->value, Role::Dispatcher->value])->get();
        if ($users->isEmpty()) {
            return 0;
        }
        Notification::send($users, new OrderAlert($order, $kind, $title, $body, ['severity' => 'alert', 'href' => $href]));

        return 1;
    }
}
