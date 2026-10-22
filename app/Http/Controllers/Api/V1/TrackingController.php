<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;

/**
 * Suivi public d'un colis par son code (sans authentification).
 * N'expose ni adresse, ni téléphone, ni montant.
 */
class TrackingController extends Controller
{
    private const PUBLIC_STATUSES = [
        OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::PickedUp, OrderStatus::OutForDelivery,
        OrderStatus::Delivered, OrderStatus::DeliveryFailed, OrderStatus::Rescheduled, OrderStatus::Returned,
        OrderStatus::Cancelled,
    ];

    public function __invoke(string $code): JsonResponse
    {
        $order = Order::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('tracking_code', mb_strtoupper(trim($code)))
            ->tap(fn ($q) => app(Tenancy::class)->restrict($q))
            ->with(['merchant' => fn ($q) => $q->withoutGlobalScopes(), 'deliveryZone' => fn ($q) => $q->withoutGlobalScopes(), 'deliveryCourier.user'])
            ->first();

        abort_if($order === null, 404);

        $timeline = $order->events()
            ->where('type', '!=', OrderEventType::Edited)
            ->whereIn('to_status', OrderStatus::values(self::PUBLIC_STATUSES))
            ->orderBy('id')
            ->get()
            ->map(fn (OrderEvent $e) => [
                'status' => $e->to_status->value,
                'label' => $order->is_shipping && $e->to_status === OrderStatus::Delivered ? 'Expédié' : $e->to_status->label(),
                'rescheduled_to' => $e->rescheduled_to?->toDateString(),
                'at' => $e->created_at,
            ]);

        $courierFirstName = $order->status === OrderStatus::OutForDelivery
            ? strtok((string) $order->deliveryCourier?->user?->name, ' ')
            : null;

        return response()->json(['data' => [
            'tracking_code' => $order->tracking_code,
            'status' => $order->status->value,
            'status_label' => $order->statusLabel(),
            'shipping_carrier' => $order->status === OrderStatus::Delivered ? $order->shipping_carrier : null,
            'merchant_name' => $order->merchant?->business_name,
            'delivery_zone' => $order->deliveryZone?->name,
            'scheduled_date' => $order->delivery_scheduled_date?->toDateString(),
            'courier_first_name' => $courierFirstName ?: null,
            'delivered_at' => $order->delivered_at,
            'timeline' => $timeline,
        ]]);
    }
}
