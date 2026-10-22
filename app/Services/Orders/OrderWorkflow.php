<?php

namespace App\Services\Orders;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\IncidentReason;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;
use App\Services\Assignment\AutoAssigner;
use App\Services\Finance\FinanceRecorder;
use App\Services\Merchants\MerchantLocation;
use App\Services\Stock\StockKeeper;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Machine à états des courses : vérifie qui peut faire quelle transition,
 * applique les effets (assignations, tentatives, horodatages) et journalise.
 */
class OrderWorkflow
{
    public function __construct(
        private readonly OrderJournal $journal,
        private readonly FinanceRecorder $finance,
        private readonly StockKeeper $stock,
        private readonly MerchantLocation $locations,
        private readonly PickupGroups $pickups,
        private readonly AutoAssigner $autoAssigner,
    ) {}

    /**
     * @param  array{
     *     incident_reason_id?: int|null,
     *     note?: string|null,
     *     rescheduled_to?: string|null,
     *     delivery_code?: string|null,
     *     collected_amount?: int|null,
     *     cancel_reason?: string|null,
     *     lat?: float|null,
     *     lng?: float|null,
     *     offline?: bool,
     *     occurred_at?: Carbon,
     * }  $context
     */
    public function transition(User $actor, Order $order, OrderStatus $to, array $context = []): Order
    {
        return DB::transaction(function () use ($actor, $order, $to, $context) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $from = $order->status;

            $reason = $this->resolveReason($order, $context['incident_reason_id'] ?? null);

            // Un échec avec un motif « report » devient directement un report daté
            if ($to === OrderStatus::DeliveryFailed && $reason?->requires_date) {
                $to = OrderStatus::Rescheduled;
            }

            if (! $from->canTransitionTo($to)) {
                throw new BusinessRuleException("Transition impossible : « {$from->label()} » → « {$to->label()} ».", 'status');
            }

            $this->authorizeActor($actor, $order, $from, $to);
            $this->validate($order, $from, $to, $reason, $context);

            $assignment = $this->applyEffects($order, $from, $to, $reason, $context);

            $order->status = $to;
            $order->save();

            $this->finance->onTransition($order, $to, $assignment, $context, $actor);
            $this->stock->onTransition($order, $to, $actor);

            $this->journal->record($order, $actor, $reason ? OrderEventType::Incident : OrderEventType::StatusChanged, [
                // Action faite hors connexion : à son heure réelle dans le journal
                ...(isset($context['occurred_at']) ? ['created_at' => $context['occurred_at']] : []),
                'assignment_id' => $assignment?->id,
                'from_status' => $from,
                'to_status' => $to,
                'incident_reason_id' => $reason?->id,
                'rescheduled_to' => $context['rescheduled_to'] ?? null,
                'note' => $context['note'] ?? $context['cancel_reason'] ?? $this->defaultNote($order, $from, $to),
                'lat' => $context['lat'] ?? null,
                'lng' => $context['lng'] ?? null,
                'meta' => array_filter([
                    'collected_amount' => $to === OrderStatus::Delivered ? $order->collected_amount : null,
                    'payment_method' => $to === OrderStatus::Delivered && $order->collected_amount > 0 ? ($context['payment_method'] ?? 'cash') : null,
                    'attempts_count' => $order->attempts_count,
                    'shipping_fee' => $to === OrderStatus::Delivered && $order->is_shipping ? $order->shipping_fee : null,
                    'shipping_carrier' => $to === OrderStatus::Delivered && $order->is_shipping ? $order->shipping_carrier : null,
                    'shipping_reference' => $to === OrderStatus::Delivered && $order->is_shipping ? $order->shipping_reference : null,
                    'offline' => ! empty($context['offline']) ? true : null,
                    'delivery_code_unverified' => $to === OrderStatus::Delivered && $order->delivery_code_unverified_at ? true : null,
                ], fn ($v) => $v !== null),
            ]);

            // Course validée : le colis va au livreur déjà prévu chez ce client, sinon au livreur du planning
            if ($from === OrderStatus::Pending && $to === OrderStatus::Confirmed
                && ($this->pickups->attachToRunningPickup($order) || $this->autoAssigner->pickup($order))) {
                $order = $order->fresh();
            }

            // Colis prêt (récupéré ou au dépôt) : livraison du jour même confiée automatiquement, si l'entreprise le veut
            if (in_array($to, [OrderStatus::PickedUp, OrderStatus::AtHub], true) && $this->autoAssigner->delivery($order)) {
                $order = $order->fresh();
            }

            // Colis récupéré avec la position du livreur : la position du marchand s'affine
            if ($to === OrderStatus::PickedUp && isset($context['lat'], $context['lng']) && $order->merchant) {
                $this->locations->learn($order->merchant);
            }

            return $order;
        });
    }

    private function resolveReason(Order $order, ?int $reasonId): ?IncidentReason
    {
        if ($reasonId === null) {
            return null;
        }

        $reason = IncidentReason::availableTo($order->company_id)->find($reasonId);

        if ($reason === null) {
            throw new BusinessRuleException('Motif d\'incident inconnu.', 'incident_reason_id');
        }

        return $reason;
    }

    private function authorizeActor(User $actor, Order $order, OrderStatus $from, OrderStatus $to): void
    {
        // Déclarer un colis perdu engage la caisse : administrateur (dispatch + finance) uniquement
        if ($to === OrderStatus::Lost) {
            if ($actor->can(Permission::OrdersDispatch->value) && $actor->can(Permission::FinanceManage->value)) {
                return;
            }

            throw new AuthorizationException('Seul un administrateur peut déclarer un colis perdu.');
        }

        if ($actor->can(Permission::OrdersDispatch->value)) {
            return;
        }

        if ($actor->merchant_id !== null) {
            $merchantAllowed = $actor->merchant_id === $order->merchant_id && match ($to) {
                OrderStatus::Cancelled => $from->isBeforePickup(),
                // Report, ou nouvelle date tant qu'aucun livreur n'est reparti avec le colis
                OrderStatus::Rescheduled => $from === OrderStatus::DeliveryFailed
                    || ($from === OrderStatus::Rescheduled && ! $order->activeAssignment(AssignmentType::Delivery)),
                default => false,
            };

            if ($merchantAllowed) {
                return;
            }

            throw new AuthorizationException('Cette action est réservée à l\'entreprise de livraison.');
        }

        if ($actor->isCourier()) {
            $courierId = $actor->courier?->id;
            $holds = fn (AssignmentType $type) => $order->assignments()->active()
                ->where('type', $type->value)->where('courier_id', $courierId)->exists();

            $allowed = match ($to) {
                OrderStatus::PickupInProgress, OrderStatus::PickedUp => $holds(AssignmentType::Pickup),
                OrderStatus::Confirmed => in_array($from, [OrderStatus::PickupAssigned, OrderStatus::PickupInProgress], true)
                    && $holds(AssignmentType::Pickup),
                OrderStatus::OutForDelivery, OrderStatus::Delivered, OrderStatus::DeliveryFailed => $holds(AssignmentType::Delivery),
                OrderStatus::Rescheduled => $from === OrderStatus::OutForDelivery && $holds(AssignmentType::Delivery),
                // Dépôt au hub : par le livreur qui a le colis en main
                OrderStatus::AtHub => ($from === OrderStatus::PickedUp && $order->pickup_courier_id === $courierId)
                    || $order->delivery_courier_id === $courierId,
                OrderStatus::Returning, OrderStatus::Returned => $holds(AssignmentType::Return),
                default => false,
            };

            if ($allowed) {
                return;
            }

            throw new AuthorizationException('Cette course ne vous est pas assignée pour cette étape.');
        }

        // Agent de dépôt : réception au hub, préparation des commandes d'entrepôt
        if ($to === OrderStatus::AtHub && $actor->can(Permission::OrdersUpdateStatus->value)) {
            return;
        }

        // Caissier : colis ramassé rendu par le livreur lors du point de caisse
        if ($to === OrderStatus::AtHub && $from === OrderStatus::PickedUp && $order->held_by_courier_id !== null
            && $actor->can(Permission::FinanceManage->value)) {
            return;
        }

        // Remise en stock d'une commande d'entrepôt
        if ($to === OrderStatus::Returned && $order->fromWarehouse() && $actor->can(Permission::StockManage->value)) {
            return;
        }

        throw new AuthorizationException;
    }

    private function validate(Order $order, OrderStatus $from, OrderStatus $to, ?IncidentReason $reason, array $context): void
    {
        $stage = $from->isBeforePickup() ? 'pickup' : 'delivery';

        if ($reason !== null && ! $reason->appliesTo($stage)) {
            throw new BusinessRuleException('Ce motif ne s\'applique pas à cette étape.', 'incident_reason_id');
        }

        $returnStatuses = [OrderStatus::ReturnAssigned, OrderStatus::Returning];

        if ($to === OrderStatus::AtHub && $from === OrderStatus::Confirmed && ! $order->fromWarehouse()) {
            throw new BusinessRuleException('Le colis doit d\'abord être ramassé chez le marchand.', 'status');
        }

        if ($to === OrderStatus::Returned && ! in_array($from, $returnStatuses, true) && ! $order->fromWarehouse()) {
            throw new BusinessRuleException('Assignez d\'abord un livreur pour le retour du colis au marchand.', 'status');
        }

        match ($to) {
            OrderStatus::OutForDelivery => $this->validateOutForDelivery($order),
            OrderStatus::DeliveryFailed => $reason ?? throw new BusinessRuleException('Indiquez le motif de l\'échec.', 'incident_reason_id'),
            OrderStatus::Rescheduled => $this->validateRescheduleDate($context['rescheduled_to'] ?? null),
            // Retour à « Validée » depuis le ramassage = échec du ramassage, motif obligatoire
            OrderStatus::Confirmed => $from === OrderStatus::Pending
                ? null
                : ($reason ?? throw new BusinessRuleException('Indiquez le motif de l\'échec du ramassage.', 'incident_reason_id')),
            OrderStatus::Delivered => $this->validateDelivery($order, $context),
            OrderStatus::Lost => filled($context['lost_reason'] ?? null) ? null
                : throw new BusinessRuleException('Déclarez la perte depuis l\'action « Colis perdu ».', 'status'),
            default => null,
        };
    }

    private function validateOutForDelivery(Order $order): void
    {
        if ($order->activeAssignment(AssignmentType::Delivery) === null) {
            throw new BusinessRuleException('Assignez d\'abord un livreur pour la livraison.', 'status');
        }

        if ($order->attempts_count >= $order->max_attempts) {
            throw new BusinessRuleException('Nombre maximal de tentatives atteint : organisez le retour du colis.', 'status');
        }
    }

    private function validateRescheduleDate(?string $date): void
    {
        if ($date === null || ! strtotime($date)) {
            throw new BusinessRuleException('Indiquez la nouvelle date de livraison.', 'rescheduled_to');
        }

        if (Carbon::parse($date)->isBefore(today())) {
            throw new BusinessRuleException('La date de report ne peut pas être passée.', 'rescheduled_to');
        }
    }

    private function validateDelivery(Order $order, array $context): void
    {
        if ($order->is_shipping) {
            // Expédition : le colis est confié à un transporteur, sans remise au destinataire
            if (blank($context['shipping_carrier'] ?? null)) {
                throw new BusinessRuleException('Indiquez la compagnie ou la gare d\'expédition.', 'shipping_carrier');
            }

            if (! isset($context['shipping_fee']) || ! is_int($context['shipping_fee']) || $context['shipping_fee'] < 0) {
                throw new BusinessRuleException('Indiquez les frais d\'expédition payés (0 si rien n\'a été payé).', 'shipping_fee');
            }
        } elseif ($order->company->require_delivery_code) {
            $code = (string) ($context['delivery_code'] ?? '');

            if ($code === '' || ! hash_equals((string) $order->delivery_code, $code)) {
                // Livraison faite hors connexion : le client est déjà parti, la livraison est gardée
                // mais signalée à l'entreprise (code à vérifier)
                if (empty($context['offline'])) {
                    throw new BusinessRuleException('Code de livraison incorrect.', 'delivery_code');
                }
                $order->delivery_code_unverified_at = now();
            }
        }

        $collected = $context['collected_amount'] ?? null;

        if ($collected !== null && (! is_int($collected) || $collected < 0)) {
            throw new BusinessRuleException('Montant encaissé invalide.', 'collected_amount');
        }

        $method = PaymentMethod::tryFrom($context['payment_method'] ?? 'cash');

        if (! empty($context['received_by_company']) && $method === PaymentMethod::Cash) {
            throw new BusinessRuleException('Des espèces ne peuvent pas être reçues directement sur le compte de l\'entreprise.', 'received_by_company');
        }
    }

    /**
     * Met à jour assignations, horodatages et compteurs. Retourne l'assignation concernée.
     */
    private function applyEffects(Order $order, OrderStatus $from, OrderStatus $to, ?IncidentReason $reason, array $context): ?OrderAssignment
    {
        // Action faite hors connexion : horodatée à son heure réelle
        $now = $context['occurred_at'] ?? now();
        $pickup = $order->activeAssignment(AssignmentType::Pickup);
        $delivery = $order->activeAssignment(AssignmentType::Delivery);
        $return = $order->activeAssignment(AssignmentType::Return);

        if ($reason !== null) {
            $order->last_incident_reason_id = $reason->id;
        }

        switch ($to) {
            case OrderStatus::Confirmed:
                if ($from === OrderStatus::Pending) {
                    $order->confirmed_at = $now;
                } else {
                    // Échec du ramassage : l'assignation échoue, la course attend un nouveau ramasseur
                    $this->finish($pickup, AssignmentStatus::Failed);
                    $order->pickup_courier_id = null;
                }

                return $pickup;

            case OrderStatus::PickupInProgress:
                $this->start($pickup);

                return $pickup;

            case OrderStatus::PickedUp:
                $this->finish($pickup, AssignmentStatus::Completed);
                $order->picked_up_at = $now;
                $order->handTo($order->pickup_courier_id);

                return $pickup;

            case OrderStatus::AtHub:
                if ($from === OrderStatus::Confirmed) {
                    // Commande d'entrepôt préparée : prête pour la livraison
                    $order->prepared_at = $now;

                    return null;
                }

                // Colis rapporté au dépôt sans tentative : la livraison prévue est annulée
                $this->finish($delivery, AssignmentStatus::Cancelled);
                $order->handTo(null);

                return null;

            case OrderStatus::OutForDelivery:
                $this->start($delivery);
                $order->handTo($order->delivery_courier_id);

                return $delivery;

            case OrderStatus::Delivered:
                $this->finish($delivery, AssignmentStatus::Completed);
                $order->delivered_at = $now;
                $order->handTo(null);
                $order->collected_amount = $context['collected_amount'] ?? $order->cod_amount;
                if ($order->is_shipping) {
                    $order->shipping_fee = $context['shipping_fee'];
                    $order->shipping_carrier = trim($context['shipping_carrier']);
                    $order->shipping_reference = filled($context['shipping_reference'] ?? null) ? trim($context['shipping_reference']) : null;
                }
                $order->recipient?->increment('deliveries_count');

                return $delivery;

            case OrderStatus::DeliveryFailed:
            case OrderStatus::Rescheduled:
                if ($from === OrderStatus::OutForDelivery) {
                    $this->finish($delivery, AssignmentStatus::Failed);

                    if ($reason?->counts_as_attempt ?? $to === OrderStatus::DeliveryFailed) {
                        $order->attempts_count++;
                        $order->recipient?->increment('failed_count');
                    }
                }

                if ($to === OrderStatus::Rescheduled) {
                    $order->delivery_scheduled_date = $context['rescheduled_to'];
                }

                if ($reason?->triggers_return || $order->attempts_count >= $order->max_attempts) {
                    $order->return_requested = true;
                }

                return $delivery;

            case OrderStatus::Returning:
                $this->start($return);
                $order->handTo($order->return_courier_id);

                return $return;

            case OrderStatus::Returned:
                if ($return === null) {
                    // Remise en stock à l'entrepôt : plus aucune mission en cours
                    $order->assignments()->active()->update(['status' => AssignmentStatus::Cancelled, 'completed_at' => $now]);
                }
                $this->finish($return, AssignmentStatus::Completed);
                $order->returned_at = $now;
                $order->handTo(null);

                return $return;

            case OrderStatus::Lost:
                $order->assignments()->active()->update(['status' => AssignmentStatus::Cancelled, 'completed_at' => $now]);
                $order->lost_at = $now;
                $order->lost_reason = $context['lost_reason'];
                $order->handTo(null);

                return null;

            case OrderStatus::Cancelled:
            case OrderStatus::Rejected:
                $order->assignments()->active()->update(['status' => AssignmentStatus::Cancelled, 'completed_at' => $now]);
                $order->cancelled_at = $now;
                $order->handTo(null);
                $order->cancel_reason = $context['cancel_reason'] ?? $context['note'] ?? null;

                return null;

            default:
                return null;
        }
    }

    private function defaultNote(Order $order, OrderStatus $from, OrderStatus $to): ?string
    {
        return match (true) {
            $to === OrderStatus::AtHub && $from === OrderStatus::Confirmed => 'Commande préparée à l\'entrepôt',
            $to === OrderStatus::Returned && $order->fromWarehouse() => 'Colis remis en stock à l\'entrepôt',
            default => null,
        };
    }

    private function start(?OrderAssignment $assignment): void
    {
        $assignment?->forceFill([
            'status' => AssignmentStatus::InProgress,
            'accepted_at' => $assignment->accepted_at ?? now(),
            'started_at' => now(),
        ])->save();
    }

    private function finish(?OrderAssignment $assignment, AssignmentStatus $status): void
    {
        $assignment?->forceFill(['status' => $status, 'completed_at' => now()])->save();
    }
}
