<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CourierRating;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Services\Orders\OrderService;
use App\Services\Ratings\RatingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suivi public d'un colis par son code (sans authentification).
 * N'expose ni adresse, ni téléphone, ni montant, ni position.
 * Le destinataire peut y envoyer sa position GPS pour guider le livreur.
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
        $order = $this->find($code);

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
            // Le destinataire peut noter la livraison (réglage de l'entreprise, 7 jours, une fois)
            'rating_open' => $order->status === OrderStatus::Delivered && $order->delivered_at?->gte(now()->subDays(7))
                && app(RatingSettings::class)->for(Company::query()->find($order->company_id))['recipient_rating']
                && ! CourierRating::withoutGlobalScopes()->where('order_id', $order->id)->where('source', CourierRating::RECIPIENT)->exists(),
            'timeline' => $timeline,
            // Position du destinataire : dite connue ou non, jamais renvoyée
            'location_shared' => $order->delivery_lat !== null && $order->delivery_lng !== null,
            'can_share_location' => OrderService::acceptsRecipientLocation($order),
        ]]);
    }

    /** Position GPS envoyée par le destinataire (bouton « Envoyer ma position » de la page de suivi) */
    public function location(Request $request, string $code, OrderService $orders): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $orders->shareRecipientLocation($this->find($code), (float) $data['lat'], (float) $data['lng']);

        return response()->json(['data' => ['location_shared' => true]]);
    }

    private function find(string $code): Order
    {
        $order = Order::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('tracking_code', mb_strtoupper(trim($code)))
            ->with(['merchant' => fn ($q) => $q->withoutGlobalScopes(), 'deliveryZone' => fn ($q) => $q->withoutGlobalScopes(), 'deliveryCourier.user'])
            ->first();

        abort_if($order === null, 404);

        return $order;
    }
}
