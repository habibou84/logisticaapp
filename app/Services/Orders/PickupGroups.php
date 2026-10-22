<?php

namespace App\Services\Orders;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ramassages regroupés par client : un e-commerçant a souvent plusieurs colis à la même adresse.
 *  - le dispatch voit les colis à ramasser regroupés par client et adresse, et assigne le lot d'un coup ;
 *  - un colis validé alors qu'un livreur est déjà prévu chez ce client (ramassage pas encore fait)
 *    lui est ajouté automatiquement.
 */
class PickupGroups
{
    public function __construct(
        private readonly OrderDispatcher $dispatcher,
        private readonly OrderJournal $journal,
    ) {}

    /**
     * Colis validés, sans ramasseur, à aller chercher chez le client (hors commandes d'entrepôt),
     * regroupés par client et adresse de ramassage.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function toAssign(User $actor, ?string $day = null): Collection
    {
        $orders = Order::query()
            ->visibleTo($actor)
            ->where('status', OrderStatus::Confirmed)
            ->whereNull('pickup_hub_id')
            ->whereDoesntHave('assignments', fn ($q) => $q->active()->where('type', AssignmentType::Pickup->value))
            ->with(['merchant:id,business_name,phone', 'pickupZone:id,name', 'deliveryZone:id,name'])
            ->orderBy('confirmed_at')
            ->get();

        return $orders
            ->groupBy(fn (Order $o) => self::key($o))
            ->map(function (Collection $group, string $key) use ($day) {
                /** @var Order $first */
                $first = $group->first();

                return [
                    'key' => $key,
                    'merchant' => $first->merchant ? [
                        'id' => $first->merchant->id,
                        'business_name' => $first->merchant->business_name,
                        'phone' => $first->merchant->phone,
                    ] : null,
                    'pickup' => [
                        'zone_id' => $first->pickup_zone_id,
                        'zone_name' => $first->pickupZone?->name,
                        'address' => $first->pickup_address,
                        'contact_name' => $first->pickup_contact_name,
                        'phone' => $first->pickup_phone,
                        'lat' => $first->pickup_lat,
                        'lng' => $first->pickup_lng,
                    ],
                    'count' => $group->count(),
                    // Jour choisi : colis à livrer après ce jour (ramassage en avance possible)
                    'later_count' => $group->filter(fn (Order $o) => $this->isLater($o, $day))->count(),
                    'cod_total' => (int) $group->sum('cod_amount'),
                    'waiting_since' => $group->min('confirmed_at'),
                    'orders' => $group->map(fn (Order $o) => [
                        'id' => $o->id,
                        'tracking_code' => $o->tracking_code,
                        'recipient_name' => $o->recipient_name,
                        'delivery_zone_name' => $o->deliveryZone?->name,
                        'cod_amount' => (int) $o->cod_amount,
                        'is_express' => (bool) $o->is_express,
                        'confirmed_at' => $o->confirmed_at,
                        'delivery_day' => self::deliveryDay($o),
                        'later' => $this->isLater($o, $day),
                    ])->values(),
                ];
            })
            ->sortBy([['pickup.zone_name', 'asc'], ['count', 'desc']])
            ->values();
    }

    /** Jour de livraison : la date prévue, sinon le jour de création (comme Order::DELIVERY_DAY_SQL) */
    public static function deliveryDay(Order $order): string
    {
        return ($order->delivery_scheduled_date ?? $order->created_at)->toDateString();
    }

    private function isLater(Order $order, ?string $day): bool
    {
        return $day !== null && self::deliveryDay($order) > $day;
    }

    /**
     * Colis tout juste validé : si un livreur est déjà prévu chez ce client à cette adresse (ramassage
     * assigné ou en route, pas encore fait), le colis lui est ajouté. Renvoie la mission créée, ou null.
     */
    public function attachToRunningPickup(Order $order): ?OrderAssignment
    {
        if ($order->status !== OrderStatus::Confirmed || $order->fromWarehouse() || $order->merchant_id === null) {
            return null;
        }

        $sibling = OrderAssignment::query()
            ->active()
            ->where('type', AssignmentType::Pickup->value)
            ->whereHas('order', fn (Builder $q) => $this->sameStop($q, $order)
                ->whereIn('status', [OrderStatus::PickupAssigned->value, OrderStatus::PickupInProgress->value]))
            ->with('courier.user')
            ->latest('assigned_at')
            ->first();

        if ($sibling === null || ! $sibling->courier?->user?->isActive()) {
            return null;
        }

        $actor = User::find($sibling->assigned_by)
            ?? User::forCompany($order->company_id)->where('status', 'active')->role(Role::Admin->value)->first();
        if ($actor === null) {
            return null;
        }

        try {
            $assignment = $this->dispatcher->assign($actor, $order, AssignmentType::Pickup, $sibling->courier);
        } catch (BusinessRuleException) {
            return null;
        }

        // Le livreur a déjà accepté ce ramassage : le colis ajouté l'est aussi
        if (in_array($sibling->status, [AssignmentStatus::Accepted, AssignmentStatus::InProgress], true)) {
            $assignment->forceFill(['status' => AssignmentStatus::Accepted, 'accepted_at' => now()])->save();
        }

        $this->journal->record($order, null, OrderEventType::Note, [
            'note' => "Ajouté automatiquement au ramassage déjà confié à {$sibling->courier->user->name} chez ce client.",
            'visible_to_merchant' => false,
        ]);

        return $assignment;
    }

    /**
     * Même client, même adresse de ramassage.
     */
    private function sameStop(Builder $query, Order $order): Builder
    {
        return $query->whereKeyNot($order->id)
            ->where('merchant_id', $order->merchant_id)
            ->where('pickup_zone_id', $order->pickup_zone_id)
            ->whereRaw('LOWER(TRIM(COALESCE(pickup_address, \'\'))) = ?', [self::normalize($order->pickup_address)])
            ->whereNull('pickup_hub_id');
    }

    public static function key(Order $order): string
    {
        return $order->merchant_id.'-'.$order->pickup_zone_id.'-'.substr(md5(self::normalize($order->pickup_address)), 0, 8);
    }

    private static function normalize(?string $address): string
    {
        return mb_strtolower(trim((string) $address));
    }
}
