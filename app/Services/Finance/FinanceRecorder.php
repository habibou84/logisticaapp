<?php

namespace App\Services\Finance;

use App\Enums\AssignmentType;
use App\Enums\EarningType;
use App\Enums\ExpenseType;
use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use App\Enums\PayEvent;
use App\Enums\PaymentMethod;
use App\Models\CashCollection;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;

/**
 * Traduit les étapes d'une course en écritures financières (appelé par
 * OrderWorkflow dans la même transaction) :
 * - livrée : encaissement, crédit du marchand, frais retenus, gain du livreur ;
 * - expédiée (zone d'expédition) : en plus, frais de gare facturés au marchand
 *   et remboursés au livreur sur son versement s'il les a avancés ;
 * - récupérée : gain du ramasseur ;
 * - retournée : frais de retour facturés au marchand, gain du livreur.
 */
class FinanceRecorder
{
    public function __construct(
        private readonly OrderExpenses $expenses,
        private readonly CourierPay $pay,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function onTransition(Order $order, OrderStatus $to, ?OrderAssignment $assignment, array $context, User $actor): void
    {
        $courier = $assignment?->courier;

        match ($to) {
            OrderStatus::PickedUp => $this->pay->record($courier, PayEvent::Pickup, $order),
            OrderStatus::Delivered => $this->delivered($order, $courier, $context, $actor),
            OrderStatus::Returned => $this->returned($order, $courier, $actor),
            OrderStatus::Lost => $this->lost($order, $context, $actor),
            // Déplacement du livreur pour une livraison qui n'a pas abouti (échec ou report sur place)
            OrderStatus::DeliveryFailed, OrderStatus::Rescheduled => $assignment?->type === AssignmentType::Delivery
                ? $this->pay->record($courier, PayEvent::FailedAttempt, $order) : null,
            default => null,
        };
    }

    private function delivered(Order $order, ?Courier $courier, array $context, User $actor): void
    {
        $collected = (int) $order->collected_amount;

        if ($collected > 0) {
            $method = PaymentMethod::tryFrom($context['payment_method'] ?? '') ?? PaymentMethod::Cash;

            CashCollection::create([
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'courier_id' => $courier?->id,
                'amount_expected' => $order->cod_amount,
                'amount_collected' => $collected,
                'method' => $method,
                'received_by_company' => $method !== PaymentMethod::Cash && (bool) ($context['received_by_company'] ?? false),
                'transaction_ref' => $context['transaction_ref'] ?? null,
                'collected_at' => now(),
            ]);

            $this->ledger($order, LedgerEntryType::CodCredit, $collected, $actor, 'Encaissé à la livraison');
        }

        // Frais retenus quel que soit le payeur : payés par le client, ils sont inclus dans l'encaissement
        $this->ledger($order, LedgerEntryType::DeliveryFee, -$order->totalFees(), $actor, 'Frais de livraison');

        // Frais de gare : facturés au marchand ; payés par le livreur (de sa poche ou avec une
        // avance de la caisse, déduits de son versement) ou directement par l'agence
        if ($order->is_shipping && $order->shipping_fee > 0) {
            $paidBy = ($context['shipping_paid_by'] ?? 'courier') === 'company' || $courier === null ? 'company' : 'courier';

            $this->expenses->add($actor, $order, [
                'type' => ExpenseType::Shipping,
                'label' => collect([$order->shipping_carrier, $order->shipping_reference ? 'ticket '.$order->shipping_reference : null])->filter()->implode(', '),
                'amount' => (int) $order->shipping_fee,
                'paid_by' => $paidBy,
                'courier_id' => $courier?->id,
                'billed_to' => 'merchant',
            ], journal: false);
        }

        $this->pay->record($courier, $order->is_shipping ? PayEvent::Shipping : PayEvent::Delivery, $order);
    }

    private function returned(Order $order, ?Courier $courier, User $actor): void
    {
        $percent = $order->company->return_fee_percent;
        $fee = (int) round($order->totalFees() * $percent / 100);

        $this->ledger($order, LedgerEntryType::ReturnFee, -$fee, $actor, "Frais de retour ({$percent} % des frais de livraison)");

        $this->pay->record($courier, PayEvent::Return, $order);
    }

    /**
     * Colis perdu : indemnité au marchand, retenue éventuelle sur la paie du livreur responsable.
     */
    private function lost(Order $order, array $context, User $actor): void
    {
        $this->ledger($order, LedgerEntryType::LostCompensation, (int) ($context['compensation'] ?? 0), $actor, 'Indemnité pour le colis perdu');

        $deduction = (int) ($context['courier_deduction'] ?? 0);
        $courier = isset($context['responsible_courier_id']) ? Courier::find($context['responsible_courier_id']) : null;

        if ($courier && $deduction > 0) {
            CourierEarning::create([
                'company_id' => $courier->company_id,
                'courier_id' => $courier->id,
                'type' => EarningType::LostParcel,
                'amount' => -$deduction,
                'order_id' => $order->id,
                'description' => 'Colis perdu '.$order->tracking_code,
                'created_by' => $actor->id,
            ]);
        }
    }

    private function ledger(Order $order, LedgerEntryType $type, int $amount, User $actor, string $description): void
    {
        if ($amount === 0) {
            return;
        }

        MerchantLedgerEntry::create([
            'company_id' => $order->company_id,
            'merchant_id' => $order->merchant_id,
            'type' => $type,
            'amount' => $amount,
            'order_id' => $order->id,
            'description' => $description,
            'created_by' => $actor->id,
        ]);
    }
}
