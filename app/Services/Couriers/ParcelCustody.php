<?php

namespace App\Services\Couriers;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderJournal;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Colis chez les livreurs : ce que chacun a encore en main (ramassé, en livraison,
 * échec ou report non rendu) et la réception de ces colis au dépôt, notamment
 * lors du point de caisse.
 */
class ParcelCustody
{
    // Le livreur est en route avec ces colis : ils ne peuvent pas être rendus au dépôt
    public const ON_THE_ROAD = [OrderStatus::OutForDelivery, OrderStatus::Returning];

    public function __construct(
        private readonly OrderWorkflow $workflow,
        private readonly OrderJournal $journal,
    ) {}

    public static function heldQuery(): Builder
    {
        return Order::query()->whereNotNull('held_by_courier_id');
    }

    /**
     * Colis en main d'un livreur, du plus ancien au plus récent.
     *
     * @return Collection<int, Order>
     */
    public function held(Courier $courier): Collection
    {
        return self::heldQuery()->where('held_by_courier_id', $courier->id)
            ->with(['merchant:id,business_name', 'deliveryZone:id,name', 'lastIncidentReason:id,label'])
            ->orderBy('held_since')->orderBy('id')
            ->get();
    }

    /**
     * Courses que le livreur n'a pas encore clôturées (ni livrées ni déclarées en échec).
     *
     * @return Collection<int, Order>
     */
    public function onTheRoad(Courier $courier): Collection
    {
        return self::heldQuery()->where('held_by_courier_id', $courier->id)
            ->where('status', OrderStatus::OutForDelivery->value)
            ->orderBy('held_since')->orderBy('id')
            ->get(['id', 'tracking_code', 'recipient_name', 'status', 'held_since']);
    }

    /**
     * Réception au dépôt des colis rendus par le livreur. Un colis ramassé passe
     * « Au dépôt » ; les autres gardent leur statut (échec, report…) pour que le
     * dispatch décide de la suite, mais ne sont plus chez le livreur.
     *
     * @param  list<int>  $orderIds
     * @return Collection<int, Order> colis reçus
     */
    public function receive(User $actor, Courier $courier, array $orderIds): Collection
    {
        $orderIds = array_values(array_unique($orderIds));

        if ($orderIds === []) {
            return collect();
        }

        return DB::transaction(function () use ($actor, $courier, $orderIds) {
            $orders = Order::query()->whereIn('id', $orderIds)
                ->where('held_by_courier_id', $courier->id)
                ->orderBy('id') // ordre stable de la liste des colis rendus (PostgreSQL ne garantit aucun ordre)
                ->lockForUpdate()
                ->get();

            if ($orders->count() !== count($orderIds)) {
                throw new BusinessRuleException('Certains colis ne sont pas (ou plus) chez ce livreur.', 'returned_order_ids');
            }

            $onTheRoad = $orders->filter(fn (Order $o) => in_array($o->status, self::ON_THE_ROAD, true));

            if ($onTheRoad->isNotEmpty()) {
                throw new BusinessRuleException(
                    'Course(s) encore en route : '.$onTheRoad->pluck('tracking_code')->implode(', ').'. Le livreur doit d\'abord indiquer si elles sont livrées ou en échec.',
                    'returned_order_ids',
                );
            }

            $note = 'Colis rendu au dépôt par '.($courier->user?->name ?? 'le livreur');
            // Rendu au dépôt : la garde prévue par une mission ramassage s'arrête là
            Order::query()->whereKey($orders->modelKeys())->update(['custody_plan' => null]);
            $orders->each(fn (Order $o) => $o->custody_plan = null);

            return $orders->map(function (Order $order) use ($actor, $note) {
                if ($order->status === OrderStatus::PickedUp) {
                    return $this->workflow->transition($actor, $order, OrderStatus::AtHub, ['note' => $note]);
                }

                $order->handTo(null);
                $order->save();

                $this->journal->record($order, $actor, OrderEventType::ParcelReceived, [
                    'note' => $note,
                    'visible_to_merchant' => false,
                ]);

                return $order;
            });
        });
    }

    /**
     * Données d'un colis pour les écrans de caisse et de suivi.
     *
     * @return array<string, mixed>
     */
    public static function parcelData(Order $order): array
    {
        return [
            'id' => $order->id,
            'tracking_code' => $order->tracking_code,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'merchant' => $order->merchant?->business_name,
            'recipient_name' => $order->recipient_name,
            'recipient_phone' => $order->recipient_phone,
            'zone' => $order->deliveryZone?->name,
            'cod_amount' => $order->cod_amount,
            'incident' => $order->lastIncidentReason?->label,
            'rescheduled_to' => $order->status === OrderStatus::Rescheduled ? $order->delivery_scheduled_date?->toDateString() : null,
            'return_requested' => $order->return_requested,
            'held_since' => $order->held_since,
            'on_the_road' => in_array($order->status, self::ON_THE_ROAD, true),
            // Mission ramassage : gardé jusqu'à sa livraison (pas « à rendre »), ou à rapporter au dépôt
            'custody_plan' => $order->custody_plan,
            'kept_for' => PickupCustody::keptFor($order),
        ];
    }
}
