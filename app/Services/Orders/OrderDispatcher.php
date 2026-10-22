<?php

namespace App\Services\Orders;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Assignation des missions (ramassage, livraison, retour) aux livreurs.
 * Le livreur de ramassage et celui de livraison peuvent être différents :
 * chaque mission est une assignation distincte, historisée.
 */
class OrderDispatcher
{
    private const ALLOWED_FROM = [
        'pickup' => [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::PickupAssigned, OrderStatus::PickupInProgress],
        'delivery' => [
            OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::PickupAssigned, OrderStatus::PickupInProgress,
            OrderStatus::PickedUp, OrderStatus::AtHub, OrderStatus::DeliveryAssigned,
            OrderStatus::DeliveryFailed, OrderStatus::Rescheduled,
        ],
        'return' => [
            OrderStatus::PickedUp, OrderStatus::AtHub, OrderStatus::DeliveryAssigned,
            OrderStatus::DeliveryFailed, OrderStatus::Rescheduled, OrderStatus::ReturnAssigned,
        ],
    ];

    public function __construct(private readonly OrderJournal $journal) {}

    /**
     * @param  string|null  $source  planning (ou autre automatisme) : mission confiée sans le dispatch, acceptée d'office
     */
    public function assign(User $actor, Order $order, AssignmentType $type, Courier $courier, ?string $source = null): OrderAssignment
    {
        return DB::transaction(function () use ($actor, $order, $type, $courier, $source) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $from = $order->status;

            if ($courier->company_id !== $order->company_id || ! $courier->user?->isActive()) {
                throw new BusinessRuleException('Ce livreur n\'est pas disponible pour cette entreprise.', 'courier_id');
            }

            if (! in_array($from, self::ALLOWED_FROM[$type->value], true)) {
                throw new BusinessRuleException("Impossible d'assigner un {$this->lower($type)} à une course « {$from->label()} ».", 'type');
            }

            if ($order->fromWarehouse()) {
                match (true) {
                    $type === AssignmentType::Pickup => throw new BusinessRuleException('Cette commande part de l\'entrepôt : pas de ramassage chez le marchand.', 'type'),
                    $type === AssignmentType::Return => throw new BusinessRuleException('Commande d\'entrepôt : remettez le colis en stock à son retour au dépôt.', 'type'),
                    $order->prepared_at === null => throw new BusinessRuleException('Préparez d\'abord la commande à l\'entrepôt.', 'type'),
                    default => null,
                };
            }

            if ($type === AssignmentType::Delivery && $order->attempts_count >= $order->max_attempts) {
                throw new BusinessRuleException('Nombre maximal de tentatives atteint : organisez le retour du colis.', 'type');
            }

            // Une seule mission active par type : la précédente est annulée (réassignation).
            // On garde le statut d'origine pour pouvoir y revenir si le livreur refuse.
            $previous = $order->activeAssignment($type);
            $statusBefore = $from === self::assignedStatus($type) && $previous?->order_status_before
                ? $previous->order_status_before
                : $from;

            $order->assignments()->active()->where('type', $type->value)
                ->update(['status' => AssignmentStatus::Cancelled, 'completed_at' => now()]);

            if ($type === AssignmentType::Return) {
                $order->assignments()->active()->where('type', AssignmentType::Delivery->value)
                    ->update(['status' => AssignmentStatus::Cancelled, 'completed_at' => now()]);
            }

            // Acceptée d'office : mission du planning, ou règle de l'entreprise (ou du livreur) sans choix
            $accepted = $source !== null || $courier->acceptanceMode() !== Company::ACCEPT_CHOOSE;
            $assignment = $order->assignments()->create([
                'courier_id' => $courier->id,
                'type' => $type,
                'status' => $accepted ? AssignmentStatus::Accepted : AssignmentStatus::Assigned,
                'order_status_before' => $statusBefore,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'accepted_at' => $accepted ? now() : null,
                'source' => $source,
            ]);

            $to = $this->statusAfterAssignment($type, $from);

            if ($from === OrderStatus::Pending) {
                $order->confirmed_at = now();
            }

            $order->{$type->courierColumn()} = $courier->id;
            $order->status = $to;
            $order->save();

            $this->journal->record($order, $actor, OrderEventType::Assigned, [
                'assignment_id' => $assignment->id,
                'from_status' => $from,
                'to_status' => $to,
                'meta' => [
                    'type' => $type->value,
                    'type_label' => $type->label(),
                    'courier_id' => $courier->id,
                    'courier_user_id' => $courier->user_id,
                    'courier_name' => $courier->user->name,
                    'accepted' => $accepted,
                ],
            ]);

            // Colis encore chez un autre livreur (gardé après un ramassage, par exemple) : passation à prévoir
            if ($type === AssignmentType::Delivery && $order->held_by_courier_id !== null && $order->held_by_courier_id !== $courier->id) {
                $holder = Courier::query()->with('user')->find($order->held_by_courier_id);
                $order->forceFill(['custody_plan' => null])->save();
                $this->journal->record($order, $actor, OrderEventType::Note, [
                    'note' => 'Passation : le colis est chez '.($holder?->user?->name ?? 'un autre livreur')." ; {$courier->user->name} doit le récupérer auprès de lui (ou au dépôt).",
                    'visible_to_merchant' => false,
                ]);
            }

            return $assignment;
        });
    }

    /**
     * Assigne plusieurs courses au même livreur. Les erreurs n'interrompent pas le lot.
     *
     * @param  list<int>  $orderIds
     * @return array{assigned: list<int>, errors: array<int, string>, warnings: array<int, string>}
     */
    public function assignMany(User $actor, array $orderIds, AssignmentType $type, Courier $courier): array
    {
        $result = ['assigned' => [], 'errors' => [], 'warnings' => []];

        foreach (Order::whereIn('id', $orderIds)->with('holder.user')->orderBy('id')->get() as $order) {
            try {
                $this->assign($actor, $order, $type, $courier);
                $result['assigned'][] = $order->id;
                if ($type === AssignmentType::Delivery && $order->held_by_courier_id !== null && $order->held_by_courier_id !== $courier->id) {
                    $result['warnings'][$order->id] = "{$order->tracking_code} : colis chez ".($order->holder?->user?->name ?? 'un autre livreur').', prévoir la passation.';
                }
            } catch (BusinessRuleException $e) {
                $result['errors'][$order->id] = $e->getMessage();
            }
        }

        foreach (array_diff($orderIds, $result['assigned'], array_keys($result['errors'])) as $missing) {
            $result['errors'][$missing] = 'Course introuvable.';
        }

        return $result;
    }

    /**
     * Retire le livreur d'une mission (erreur d'assignation, livreur indisponible) : la course revient
     * à son état d'avant l'assignation et attend un autre livreur. Impossible une fois le livreur en
     * route avec le colis (livraison ou retour) : il faut d'abord en enregistrer l'issue.
     */
    public function unassign(User $actor, Order $order, AssignmentType $type, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($actor, $order, $type, $reason) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $assignment = $order->activeAssignment($type);

            if ($assignment === null) {
                throw new BusinessRuleException("Aucun livreur n'est assigné à ce {$this->lower($type)}.", 'type');
            }

            $from = $order->status;
            if (in_array($from, [OrderStatus::OutForDelivery, OrderStatus::Returning], true) && $type !== AssignmentType::Pickup) {
                throw new BusinessRuleException("Le livreur est en route avec le colis : enregistrez d'abord l'issue de la course.", 'type');
            }

            $assignment->forceFill(['status' => AssignmentStatus::Cancelled, 'completed_at' => now()])->save();

            // Retour à l'état d'avant l'assignation (une course en attente reste validée)
            $assigned = $type === AssignmentType::Pickup
                ? [OrderStatus::PickupAssigned, OrderStatus::PickupInProgress]
                : [self::assignedStatus($type)];
            if (in_array($from, $assigned, true)) {
                $before = $assignment->order_status_before;
                $order->status = match (true) {
                    $before === null, $before === OrderStatus::Pending, in_array($before, $assigned, true) && $type === AssignmentType::Pickup => OrderStatus::Confirmed,
                    default => $before,
                };
            }

            $order->{$type->courierColumn()} = null;
            $order->save();

            $courier = $assignment->courier()->with('user')->first();
            $this->journal->record($order, $actor, OrderEventType::Unassigned, [
                'assignment_id' => $assignment->id,
                'from_status' => $from,
                'to_status' => $order->status,
                'note' => $reason,
                'meta' => [
                    'type' => $type->value,
                    'type_label' => $type->label(),
                    'courier_id' => $courier?->id,
                    'courier_user_id' => $courier?->user_id,
                    'courier_name' => $courier?->user?->name,
                ],
                'visible_to_merchant' => false,
            ]);

            return $order;
        });
    }

    /**
     * Retire le livreur de plusieurs courses. Sans type : la mission de l'étape en cours (ramassage
     * avant récupération du colis, retour s'il est assigné, livraison sinon). Les erreurs
     * n'interrompent pas le lot.
     *
     * @param  list<int>  $orderIds
     * @return array{unassigned: list<int>, errors: array<int, string>}
     */
    public function unassignMany(User $actor, array $orderIds, ?AssignmentType $type, ?string $reason = null): array
    {
        $result = ['unassigned' => [], 'errors' => []];

        foreach (Order::whereIn('id', $orderIds)->orderBy('id')->get() as $order) {
            $stage = $type ?? match (true) {
                $order->status->isBeforePickup() => AssignmentType::Pickup,
                in_array($order->status, [OrderStatus::ReturnAssigned, OrderStatus::Returning], true) => AssignmentType::Return,
                default => AssignmentType::Delivery,
            };
            try {
                $this->unassign($actor, $order, $stage, $reason);
                $result['unassigned'][] = $order->id;
            } catch (BusinessRuleException $e) {
                $result['errors'][$order->id] = $e->getMessage();
            }
        }

        foreach (array_diff($orderIds, $result['unassigned'], array_keys($result['errors'])) as $missing) {
            $result['errors'][$missing] = 'Course introuvable.';
        }

        return $result;
    }

    /**
     * Réponse du livreur à une mission : acceptation ou refus motivé. Une mission déjà acceptée (d'office ou non)
     * mais pas commencée peut être rendue (empêchement), sauf si l'entreprise confie les missions sans refus possible.
     */
    public function respond(User $courierUser, OrderAssignment $assignment, bool $accept, ?string $reason = null): OrderAssignment
    {
        return DB::transaction(function () use ($courierUser, $assignment, $accept, $reason) {
            $assignment = OrderAssignment::query()->lockForUpdate()->findOrFail($assignment->id);

            if ($assignment->courier_id !== $courierUser->courier?->id) {
                throw new AuthorizationException('Cette mission ne vous est pas assignée.');
            }

            $impediment = ! $accept && $assignment->status === AssignmentStatus::Accepted;
            if ($impediment && ! $courierUser->courier->canReportImpediment()) {
                throw new BusinessRuleException('Les missions vous sont confiées d\'office : appelez l\'agence en cas d\'empêchement.', 'status');
            }
            if ($assignment->status !== AssignmentStatus::Assigned && ! $impediment) {
                throw new BusinessRuleException($assignment->status === AssignmentStatus::InProgress
                    ? 'Mission déjà commencée : appelez l\'agence ou signalez un problème sur la course.'
                    : 'Cette mission a déjà été traitée.', 'status');
            }

            $order = Order::query()->lockForUpdate()->findOrFail($assignment->order_id);

            if ($accept) {
                $assignment->forceFill(['status' => AssignmentStatus::Accepted, 'accepted_at' => now()])->save();
                $this->journal->record($order, $courierUser, OrderEventType::AssignmentAccepted, [
                    'assignment_id' => $assignment->id,
                    'meta' => ['type' => $assignment->type->value],
                    'visible_to_merchant' => false,
                ]);

                return $assignment;
            }

            $assignment->forceFill([
                'status' => AssignmentStatus::Refused,
                'refusal_reason' => $reason,
                'completed_at' => now(),
            ])->save();

            $from = $order->status;

            // La course revient à son état d'avant l'assignation (une course en attente reste validée)
            if ($from === self::assignedStatus($assignment->type) && $assignment->order_status_before !== null) {
                $order->status = $assignment->order_status_before === OrderStatus::Pending
                    ? OrderStatus::Confirmed
                    : $assignment->order_status_before;
            }

            $order->{$assignment->type->courierColumn()} = null;
            $order->save();

            $this->journal->record($order, $courierUser, OrderEventType::AssignmentRefused, [
                'assignment_id' => $assignment->id,
                'from_status' => $from,
                'to_status' => $order->status,
                'note' => $reason,
                'meta' => ['type' => $assignment->type->value, 'impediment' => $impediment],
                'visible_to_merchant' => false,
            ]);

            return $assignment;
        });
    }

    private static function assignedStatus(AssignmentType $type): OrderStatus
    {
        return match ($type) {
            AssignmentType::Pickup => OrderStatus::PickupAssigned,
            AssignmentType::Delivery => OrderStatus::DeliveryAssigned,
            AssignmentType::Return => OrderStatus::ReturnAssigned,
        };
    }

    private function statusAfterAssignment(AssignmentType $type, OrderStatus $from): OrderStatus
    {
        return match ($type) {
            AssignmentType::Pickup => OrderStatus::PickupAssigned,
            // Livraison planifiée avant le ramassage : la course reste à l'étape ramassage
            AssignmentType::Delivery => $from->isBeforePickup()
                ? ($from === OrderStatus::Pending ? OrderStatus::Confirmed : $from)
                : OrderStatus::DeliveryAssigned,
            AssignmentType::Return => OrderStatus::ReturnAssigned,
        };
    }

    private function lower(AssignmentType $type): string
    {
        return mb_strtolower($type->label());
    }
}
