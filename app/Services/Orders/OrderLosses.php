<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\User;

/**
 * Déclaration d'un colis perdu : la course passe « Perdu », le marchand est
 * indemnisé (écriture à son grand livre) et une retenue peut être faite sur la
 * paie du livreur responsable.
 */
class OrderLosses
{
    public function __construct(private readonly OrderWorkflow $workflow) {}

    /**
     * @param  array{reason: string, compensation: int, courier_deduction?: int|null, responsible_courier_id?: int|null}  $data
     */
    public function declare(User $actor, Order $order, array $data): Order
    {
        if ($order->status->isFinal() || $order->status->isBeforePickup()) {
            throw new BusinessRuleException('Seul un colis déjà ramassé et pas encore livré ou retourné peut être déclaré perdu.', 'status');
        }

        $deduction = (int) ($data['courier_deduction'] ?? 0);
        $responsible = $data['responsible_courier_id'] ?? $order->held_by_courier_id;

        if ($deduction > 0 && $responsible === null) {
            throw new BusinessRuleException('Indiquez le livreur responsable pour la retenue.', 'responsible_courier_id');
        }

        return $this->workflow->transition($actor, $order, OrderStatus::Lost, [
            'lost_reason' => trim($data['reason']),
            'note' => 'Colis déclaré perdu : '.trim($data['reason']),
            'compensation' => (int) $data['compensation'],
            'courier_deduction' => $deduction,
            'responsible_courier_id' => $responsible,
        ]);
    }
}
