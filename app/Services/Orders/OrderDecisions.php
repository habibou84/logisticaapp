<?php

namespace App\Services\Orders;

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Suite donnée à un colis non livré : relivrer à une date, le retourner au
 * marchand ou, pour une commande d'entrepôt, le remettre en stock. Le client
 * est prévenu par les messages liés aux événements de la course.
 */
class OrderDecisions
{
    public const REDELIVER = 'redeliver';

    public const RETURN = 'return';

    public const RESTOCK = 'restock';

    // Statuts pour lesquels une décision est attendue ou peut être revue
    public const DECIDABLE = [OrderStatus::DeliveryFailed, OrderStatus::Rescheduled, OrderStatus::AtHub];

    public function __construct(
        private readonly OrderWorkflow $workflow,
        private readonly OrderDispatcher $dispatcher,
        private readonly OrderService $orders,
    ) {}

    /**
     * reopen : l'agence relivre malgré le retour en cours (le client a changé d'avis, ou une tentative de plus est
     * accordée) tant que le colis n'est pas parti en retour.
     *
     * @param  array{decision: string, date?: string|null, courier_id?: int|null, note?: string|null, reopen?: bool}  $data
     */
    public function decide(User $actor, Order $order, array $data): Order
    {
        if (! in_array($order->status, self::DECIDABLE, true)) {
            throw new BusinessRuleException("Aucune décision à prendre pour une course « {$order->status->label()} ».", 'decision');
        }

        if ($order->activeAssignment(AssignmentType::Delivery) || $order->activeAssignment(AssignmentType::Return)) {
            throw new BusinessRuleException('Une mission est déjà en cours pour ce colis.', 'decision');
        }

        $courier = isset($data['courier_id']) ? Courier::findOrFail($data['courier_id']) : null;
        $note = filled($data['note'] ?? null) ? trim($data['note']) : null;

        return DB::transaction(fn () => match ($data['decision']) {
            self::REDELIVER => $this->redeliver($actor, ($data['reopen'] ?? false) ? $this->reopen($actor, $order) : $order, $data['date'] ?? null, $courier, $note),
            self::RETURN => $this->returnToMerchant($actor, $order, $courier, $note),
            self::RESTOCK => $this->restock($actor, $order, $note),
        })->fresh();
    }

    /** Retour annulé par l'agence : le colis peut être relivré, une tentative de plus si elles étaient épuisées */
    private function reopen(User $actor, Order $order): Order
    {
        if ($actor->merchant_id !== null) {
            throw new BusinessRuleException('Seule l\'agence peut relancer la livraison d\'un colis en retour.', 'decision');
        }
        if ($order->return_slip_id !== null) {
            throw new BusinessRuleException('Ce colis est déjà sur un bon de retour.', 'decision');
        }

        $order->forceFill([
            'return_requested' => false,
            'return_cause' => null,
            'max_attempts' => max($order->max_attempts, $order->attempts_count + 1),
        ])->save();

        return $order;
    }

    private function redeliver(User $actor, Order $order, ?string $date, ?Courier $courier, ?string $note): Order
    {
        if ($order->attempts_count >= $order->max_attempts) {
            throw new BusinessRuleException('Nombre maximal de tentatives atteint : organisez le retour du colis.', 'decision');
        }

        if ($order->return_requested) {
            throw new BusinessRuleException('Le retour de ce colis est demandé : il ne peut plus être relivré.', 'decision');
        }

        $order = $this->workflow->transition($actor, $order, OrderStatus::Rescheduled, [
            'rescheduled_to' => $date,
            'note' => $note,
        ]);

        if ($courier) {
            $this->dispatcher->assign($actor, $order, AssignmentType::Delivery, $courier);
        }

        return $order;
    }

    private function returnToMerchant(User $actor, Order $order, ?Courier $courier, ?string $note): Order
    {
        if ($order->fromWarehouse()) {
            throw new BusinessRuleException('Commande d\'entrepôt : remettez le colis en stock.', 'decision');
        }

        if (! $order->return_requested) {
            $this->orders->requestReturn($actor, $order, $note ?? 'Retour au client décidé par l\'agence');
        }

        if ($courier) {
            $this->dispatcher->assign($actor, $order->fresh(), AssignmentType::Return, $courier);
        }

        return $order;
    }

    private function restock(User $actor, Order $order, ?string $note): Order
    {
        if (! $order->fromWarehouse()) {
            throw new BusinessRuleException('Seule une commande d\'entrepôt peut être remise en stock.', 'decision');
        }

        if ($order->held_by_courier_id !== null) {
            throw new BusinessRuleException('Le colis est encore chez le livreur : recevez-le d\'abord au dépôt.', 'decision');
        }

        return $this->workflow->transition($actor, $order, OrderStatus::Returned, ['note' => $note]);
    }
}
