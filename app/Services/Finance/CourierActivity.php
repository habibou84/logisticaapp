<?php

namespace App\Services\Finance;

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Enums\PayBonusMetric;
use App\Enums\PayEvent;
use App\Models\Company;
use App\Models\OrderEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Activité des livreurs reconstituée depuis le journal des courses : étapes payables
 * (ramassage, livraison, tentative ratée, retour) avec la mission qui les a faites.
 * Sert aux primes d'objectifs et au simulateur.
 */
class CourierActivity
{
    /** @var array<int, string> */
    private array $timezones = [];

    public function timezone(int $companyId): string
    {
        return $this->timezones[$companyId] ??= Company::query()->whereKey($companyId)->value('timezone') ?: config('app.timezone');
    }

    /**
     * Bornes UTC des jours [début, fin] dans le fuseau de l'entreprise.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(int $companyId, CarbonInterface $start, CarbonInterface $end): array
    {
        $tz = $this->timezone($companyId);

        return [
            CarbonImmutable::parse($start->toDateString(), $tz)->startOfDay()->utc(),
            CarbonImmutable::parse($end->toDateString(), $tz)->endOfDay()->utc(),
        ];
    }

    /**
     * Étapes faites par les livreurs entre deux dates (jours inclus, fuseau de l'entreprise).
     *
     * @param  list<int>  $courierIds
     * @return Collection<int, array{courier_id: int, event: PayEvent, order_id: int, at: CarbonImmutable, incident_reason_id: ?int, collected: ?int}>
     */
    public function events(int $companyId, array $courierIds, CarbonInterface $start, CarbonInterface $end): Collection
    {
        [$from, $to] = $this->range($companyId, $start, $end);

        return OrderEvent::query()
            ->join('order_assignments', 'order_assignments.id', '=', 'order_events.assignment_id')
            ->join('orders', 'orders.id', '=', 'order_events.order_id')
            ->where('orders.company_id', $companyId)
            ->whereIn('order_assignments.courier_id', $courierIds)
            ->whereIn('order_events.to_status', [
                OrderStatus::PickedUp->value, OrderStatus::Delivered->value, OrderStatus::Returned->value,
                OrderStatus::DeliveryFailed->value, OrderStatus::Rescheduled->value,
            ])
            ->whereBetween('order_events.created_at', [$from, $to])
            ->orderBy('order_events.created_at')->orderBy('order_events.id')
            ->get([
                'order_events.order_id', 'order_events.to_status', 'order_events.incident_reason_id', 'order_events.meta',
                'order_events.created_at', 'order_assignments.courier_id', 'order_assignments.type as assignment_type', 'orders.is_shipping',
            ])
            ->map(function (OrderEvent $e) {
                $event = match ($e->to_status) {
                    OrderStatus::PickedUp => PayEvent::Pickup,
                    OrderStatus::Delivered => $e->getAttribute('is_shipping') ? PayEvent::Shipping : PayEvent::Delivery,
                    OrderStatus::Returned => PayEvent::Return,
                    default => $e->getAttribute('assignment_type') === AssignmentType::Delivery->value ? PayEvent::FailedAttempt : null,
                };

                return $event === null ? null : [
                    'courier_id' => (int) $e->getAttribute('courier_id'),
                    'event' => $event,
                    'order_id' => (int) $e->order_id,
                    'at' => CarbonImmutable::parse($e->created_at),
                    'incident_reason_id' => $e->incident_reason_id,
                    'collected' => $e->meta['collected_amount'] ?? null,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Indicateurs des primes sur une liste d'étapes d'un livreur.
     *
     * @param  Collection<int, array{event: PayEvent, at: CarbonImmutable}>  $events
     * @return array{deliveries: int, pickups: int, attempts: int, success_rate: int, worked_days: int}
     */
    public function measures(Collection $events, string $timezone): array
    {
        $deliveries = $events->filter(fn ($e) => in_array($e['event'], [PayEvent::Delivery, PayEvent::Shipping], true))->count();
        $attempts = $deliveries + $events->where('event', PayEvent::FailedAttempt)->count();

        return [
            PayBonusMetric::Deliveries->value => $deliveries,
            PayBonusMetric::Pickups->value => $events->where('event', PayEvent::Pickup)->count(),
            'attempts' => $attempts,
            PayBonusMetric::SuccessRate->value => $attempts > 0 ? intdiv($deliveries * 100, $attempts) : 0,
            PayBonusMetric::WorkedDays->value => $events->map(fn ($e) => $e['at']->setTimezone($timezone)->toDateString())->unique()->count(),
        ];
    }
}
