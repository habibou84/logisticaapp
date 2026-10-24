<?php

namespace App\Services\FieldEvents;

use App\Enums\AssignmentType;
use App\Enums\NotificationEvent;
use App\Enums\OrderStatus;
use App\Enums\WhatsAppTemplate;
use App\Events\CourierLocationUpdated;
use App\Exceptions\BusinessRuleException;
use App\Models\CourierAbsence;
use App\Models\FieldEvent;
use App\Models\Order;
use App\Models\User;
use App\Services\Assignment\AutoAssigner;
use App\Services\Messaging\Messenger;
use App\Services\Orders\OrderDispatcher;
use Throwable;

/**
 * Effets automatiques d'un événement terrain qui bloque le livreur (bloqué un moment ou arrêt pour la journée),
 * chacun au choix de l'administrateur :
 *  - le livreur passe hors service (plus de missions) jusqu'à sa reprise ;
 *  - ses ramassages pas encore commencés lui sont retirés et confiés à un autre (planning) ;
 *  - une journée arrêtée est notée au planning (absence du jour : son remplaçant prend ses règles) ;
 *  - les clients des courses touchées sont prévenus d'un retard (message neutre).
 * À la reprise (ou à la résolution par l'équipe), le livreur revient en service et l'absence du jour est retirée.
 * Ce qui a été fait est noté sur l'événement (effects). Un effet qui échoue ne bloque jamais le signalement.
 */
class FieldEventEffects
{
    public function __construct(
        private readonly FieldEventSettings $settings,
        private readonly OrderDispatcher $dispatcher,
        private readonly AutoAssigner $autoAssigner,
        private readonly Messenger $messenger,
    ) {}

    public function onReported(FieldEvent $event): void
    {
        if (! $event->blocksCourier()) {
            return;
        }
        $event->loadMissing(['courier.user', 'company']);
        $settings = $this->settings->for($event->company);
        $effects = $event->effects ?? [];
        $courier = $event->courier;

        if ($settings['pause_courier'] && $courier->is_available) {
            $courier->forceFill(['is_available' => false])->save();
            CourierLocationUpdated::dispatch($courier);
            $effects['paused'] = true;
        }

        if ($settings['release_pickups'] && $courier->user) {
            $effects['released'] = $this->releasePickups($courier->user, $event);
        }

        if ($event->capacity === 'stopped' && $settings['mark_planning']) {
            $today = now()->setTimezone($event->company->timezone ?: config('app.timezone'))->toDateString();
            $covered = CourierAbsence::where('courier_id', $courier->id)->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today)->exists();
            if (! $covered) {
                $effects['absence_id'] = CourierAbsence::create([
                    'company_id' => $event->company_id,
                    'courier_id' => $courier->id,
                    'starts_on' => $today,
                    'ends_on' => $today,
                    'reason' => "Événement terrain : {$event->type_label}",
                ])->id;
            }
        }

        if ($settings['notify_clients']) {
            $effects['notified'] = $this->notifyClients($event);
        }

        $event->forceFill(['effects' => $effects])->saveQuietly();
    }

    /**
     * Fin de l'événement : le livreur revient en service s'il avait été mis en pause (et qu'aucun autre événement
     * ne le bloque) ; l'absence du jour posée par l'événement est retirée s'il reprend.
     */
    public function onResolved(FieldEvent $event, bool $resumed = false): void
    {
        $event->loadMissing('courier');
        $effects = $event->effects ?? [];
        $courier = $event->courier;
        $stillBlocked = FieldEvent::where('courier_id', $courier->id)->whereKeyNot($event->id)->unresolved()->where('capacity', '!=', 'continue')->exists();

        if (($effects['paused'] ?? false) && ! ($effects['unpaused'] ?? false) && ! $stillBlocked && ! $courier->is_available) {
            $courier->forceFill(['is_available' => true])->save();
            CourierLocationUpdated::dispatch($courier);
            $effects['unpaused'] = true;
        }

        if ($resumed && isset($effects['absence_id'])) {
            CourierAbsence::whereKey($effects['absence_id'])->delete();
            unset($effects['absence_id']);
        }

        $event->forceFill(['effects' => $effects])->saveQuietly();
    }

    /**
     * Ramassages pas encore commencés du livreur (courses de l'événement) : retirés, puis confiés par le planning
     * si une règle les couvre ; sinon ils attendent un livreur (alertes « sans livreur »).
     *
     * @return list<int>
     */
    public function releasePickups(User $actor, FieldEvent $event): array
    {
        $released = [];
        $orders = $event->orders()->where('pickup_courier_id', $event->courier_id)
            ->where('status', OrderStatus::PickupAssigned->value)->get();

        foreach ($orders as $order) {
            try {
                $this->dispatcher->unassign($actor, $order, AssignmentType::Pickup, "Événement terrain : {$event->type_label}");
                $released[] = $order->id;
                $this->autoAssigner->pickup($order->fresh(), "le livreur a signalé : {$event->type_label}");
            } catch (BusinessRuleException $e) {
                // Course déjà changée entre-temps : laissée telle quelle
            }
        }

        return $released;
    }

    /**
     * Clients des courses touchées pas encore livrées : prévenus d'un retard (une fois par course).
     *
     * @return list<int>
     */
    public function notifyClients(FieldEvent $event): array
    {
        $done = $event->effects['notified'] ?? [];
        $orders = $event->orders()->with(['merchant', 'deliveryZone'])->whereNotIn('orders.id', $done)->get()
            ->filter(fn (Order $o) => ! $o->status->isFinal() && $o->merchant !== null);

        foreach ($orders as $order) {
            $recipient = trim(($order->recipient_name ?: 'votre destinataire').($order->deliveryZone ? " ({$order->deliveryZone->name})" : ''));
            try {
                $this->messenger->toMerchant($order->merchant, NotificationEvent::OrderIncident, WhatsAppTemplate::CourseDelayed,
                    [$order->merchant->business_name, $order->tracking_code, $recipient], $order);
                $done[] = $order->id;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return array_values(array_unique($done));
    }
}
