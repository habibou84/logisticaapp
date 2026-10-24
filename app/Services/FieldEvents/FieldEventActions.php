<?php

namespace App\Services\FieldEvents;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\FieldEvent;
use App\Models\Order;
use App\Models\User;
use App\Services\Couriers\CourierMessenger;
use App\Services\Orders\OrderDispatcher;
use App\Services\Orders\OrderJournal;
use Illuminate\Support\Facades\DB;

/**
 * Actions de l'équipe sur les courses d'un livreur touché par un événement terrain :
 *  - les confier à un autre livreur (ramassage, livraison ou retour selon l'étape) ; un colis que le livreur
 *    bloqué a déjà en main (même en route) passe au nouveau, qui va le récupérer auprès de lui (passation) ;
 *  - retirer ses ramassages pas encore commencés ;
 *  - prévenir les clients du retard ;
 *  - proposer les livreurs en service les plus proches.
 */
class FieldEventActions
{
    public function __construct(
        private readonly OrderDispatcher $dispatcher,
        private readonly OrderJournal $journal,
        private readonly CourierMessenger $messenger,
        private readonly FieldEventEffects $effects,
    ) {}

    /**
     * @param  list<int>  $orderIds
     * @return array{assigned: list<int>, errors: array<int, string>, handover: int}
     */
    public function reassign(User $actor, FieldEvent $event, array $orderIds, Courier $target, bool $notify = true): array
    {
        if ($target->id === $event->courier_id) {
            throw new BusinessRuleException('Choisissez un autre livreur.', 'courier_id');
        }
        $event->loadMissing('courier.user');
        $result = ['assigned' => [], 'errors' => [], 'handover' => 0];
        $handover = [];

        foreach ($event->orders()->whereIn('orders.id', $orderIds)->orderBy('orders.id')->get() as $order) {
            try {
                $held = $order->held_by_courier_id === $event->courier_id;
                $this->reassignOne($actor, $event, $order, $target);
                $result['assigned'][] = $order->id;
                if ($held) {
                    $handover[] = $order->fresh();
                }
            } catch (BusinessRuleException $e) {
                $result['errors'][$order->id] = "{$order->tracking_code} : {$e->getMessage()}";
            }
        }
        $result['handover'] = count($handover);

        // Colis à récupérer auprès du livreur bloqué : consigne au nouveau livreur, avec sa position
        if ($notify && $handover !== []) {
            $from = $event->courier?->user;
            $where = $event->lat !== null ? " Position : https://maps.google.com/?q={$event->lat},{$event->lng}" : '';
            $codes = collect($handover)->pluck('tracking_code')->implode(', ');
            $this->messenger->send($actor, $handover[0], $target,
                'Récupérez '.count($handover).' colis auprès de '.($from?->name ?? 'votre collègue').($from?->phone ? " ({$from->phone})" : '')
                ." ({$event->type_label}) : {$codes}.{$where}");
        }

        $effects = $event->effects ?? [];
        $effects['reassigned'] = array_values(array_unique([...($effects['reassigned'] ?? []), ...$result['assigned']]));
        $event->forceFill(['effects' => $effects])->saveQuietly();

        return $result;
    }

    private function reassignOne(User $actor, FieldEvent $event, Order $order, Courier $target): void
    {
        $status = $order->status;

        if ($status->isBeforePickup()) {
            $this->dispatcher->assign($actor, $order, AssignmentType::Pickup, $target);

            return;
        }
        if ($status === OrderStatus::Returning) {
            throw new BusinessRuleException('retour en route : à traiter depuis la course.');
        }
        if ($status === OrderStatus::ReturnAssigned) {
            $this->dispatcher->assign($actor, $order, AssignmentType::Return, $target);

            return;
        }
        if ($status === OrderStatus::OutForDelivery) {
            $this->stepBack($actor, $event, $order);
        }
        $this->dispatcher->assign($actor, $order->fresh(), AssignmentType::Delivery, $target);
    }

    /**
     * Colis en route chez un livreur bloqué : la livraison en cours est arrêtée, le colis reste dans ses mains
     * (« récupéré ») en attendant d'être confié à un autre. Pas une tentative de livraison.
     */
    private function stepBack(User $actor, FieldEvent $event, Order $order): void
    {
        DB::transaction(function () use ($actor, $event, $order) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== OrderStatus::OutForDelivery) {
                return;
            }
            $order->assignments()->active()->where('type', AssignmentType::Delivery->value)
                ->update(['status' => AssignmentStatus::Cancelled, 'completed_at' => now()]);
            $order->handTo($order->held_by_courier_id ?? $event->courier_id);
            $order->status = OrderStatus::PickedUp;
            $order->save();

            $this->journal->record($order, $actor, OrderEventType::StatusChanged, [
                'from_status' => OrderStatus::OutForDelivery,
                'to_status' => OrderStatus::PickedUp,
                'note' => "Livraison arrêtée : le livreur a signalé « {$event->type_label} ». Le colis va être confié à un autre livreur.",
                'visible_to_merchant' => false,
                'meta' => ['field_event_id' => $event->id],
            ]);
        });
    }

    /**
     * @return array{released: list<int>}
     */
    public function release(User $actor, FieldEvent $event): array
    {
        $released = $this->effects->releasePickups($actor, $event);
        $effects = $event->effects ?? [];
        $effects['released'] = array_values(array_unique([...($effects['released'] ?? []), ...$released]));
        $event->forceFill(['effects' => $effects])->saveQuietly();

        return ['released' => $released];
    }

    /**
     * @return array{notified: int}
     */
    public function notifyClients(FieldEvent $event): array
    {
        $before = count($event->effects['notified'] ?? []);
        $notified = $this->effects->notifyClients($event);
        $event->forceFill(['effects' => [...($event->effects ?? []), 'notified' => $notified]])->saveQuietly();

        return ['notified' => count($notified) - $before];
    }

    /**
     * Livreurs en service qui peuvent reprendre les courses : distance à vol d'oiseau du lieu de l'événement
     * (sinon de la dernière position du livreur bloqué), charge en cours.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(FieldEvent $event): array
    {
        $event->loadMissing('courier');
        $lat = $event->lat ?? $event->courier?->current_lat;
        $lng = $event->lng ?? $event->courier?->current_lng;
        $blocked = FieldEvent::unresolved()->where('capacity', '!=', 'continue')->pluck('courier_id')->all();

        return Courier::query()->with('user')->withCount('activeAssignments')
            ->where('is_available', true)->whereKeyNot($event->courier_id)->whereNotIn('id', $blocked)
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->get()
            ->map(fn (Courier $c) => [
                'id' => $c->id,
                'name' => $c->user->name,
                'phone' => $c->user->phone,
                'active' => $c->active_assignments_count,
                'distance_km' => $lat !== null && $c->current_lat !== null ? round(self::km($lat, $lng, $c->current_lat, $c->current_lng), 1) : null,
                'last_location_at' => $c->last_location_at,
            ])
            ->sortBy(fn ($c) => [$c['distance_km'] === null, $c['distance_km'] ?? 0, $c['active']])
            ->values()->all();
    }

    private static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $rad = M_PI / 180;
        $a = sin(($lat2 - $lat1) * $rad / 2) ** 2 + cos($lat1 * $rad) * cos($lat2 * $rad) * sin(($lng2 - $lng1) * $rad / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
