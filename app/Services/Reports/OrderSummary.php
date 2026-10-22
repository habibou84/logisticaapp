<?php

namespace App\Services\Reports;

use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Point détaillé sur une période (courses créées, ou à livrer, sur la période) : volumes par état et montants.
 * Utilisé par l'API (écrans) et par les rapports envoyés sur WhatsApp.
 */
class OrderSummary
{
    /**
     * @param  Builder<Order>  $orders  courses déjà filtrées (marchand, visibilité…)
     * @return array<string, mixed>
     */
    public function build(Builder $orders, CarbonInterface $from, CarbonInterface $to, string $basis = 'created'): array
    {
        $orders = $orders
            ->forPeriod($from->toDateString(), $to->toDateString(), $basis)
            ->get(['id', 'status', 'fee_payer', 'delivery_fee', 'surcharges_total', 'cod_amount', 'collected_amount', 'return_requested', 'is_shipping']);

        $count = fn (array $statuses) => $orders->whereIn('status', $statuses)->count();
        $delivered = $orders->where('status', OrderStatus::Delivered);

        $collected = $delivered->sum('collected_amount');

        // Frais retenus, tels qu'inscrits au grand livre du marchand
        $ledger = MerchantLedgerEntry::query()
            ->whereIn('order_id', $orders->pluck('id'))
            ->whereIn('type', [LedgerEntryType::DeliveryFee->value, LedgerEntryType::ReturnFee->value, LedgerEntryType::ShippingFee->value, LedgerEntryType::OtherFee->value])
            ->groupBy('type')
            ->selectRaw('type, SUM(amount) AS total')
            ->pluck('total', 'type');
        $fees = -(int) ($ledger[LedgerEntryType::DeliveryFee->value] ?? 0) - (int) ($ledger[LedgerEntryType::ReturnFee->value] ?? 0);
        // Frais payés à la gare ou au transporteur pour les colis expédiés
        $shippingFees = -(int) ($ledger[LedgerEntryType::ShippingFee->value] ?? 0);
        // Autres frais engagés pour les courses (transport, emballage…)
        $otherFees = -(int) ($ledger[LedgerEntryType::OtherFee->value] ?? 0);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'date_basis' => $basis],
            'counts' => [
                'total' => $orders->count(),
                'delivered' => $delivered->count(),
                'shipped' => $delivered->where('is_shipping', true)->count(),
                'in_progress' => $count([
                    OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::PickupAssigned, OrderStatus::PickupInProgress,
                    OrderStatus::PickedUp, OrderStatus::AtHub, OrderStatus::DeliveryAssigned, OrderStatus::OutForDelivery,
                ]),
                'failed' => $count([OrderStatus::DeliveryFailed]),
                'rescheduled' => $count([OrderStatus::Rescheduled]),
                'returning' => $count([OrderStatus::ReturnAssigned, OrderStatus::Returning]),
                'returned' => $count([OrderStatus::Returned]),
                'cancelled' => $count([OrderStatus::Cancelled, OrderStatus::Rejected]),
            ],
            'amounts' => [
                'collected' => $collected,
                'fees' => $fees,
                'shipping_fees' => $shippingFees,
                'other_fees' => $otherFees,
                'net_to_merchant' => $collected - $fees - $shippingFees - $otherFees,
            ],
            'delivery_rate' => $orders->count() > 0 ? round($delivered->count() / $orders->count() * 100, 1) : null,
            'by_status' => collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $s) => [
                $s->value => $orders->where('status', $s)->count(),
            ]),
        ];
    }
}
