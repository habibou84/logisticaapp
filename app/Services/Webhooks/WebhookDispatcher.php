<?php

namespace App\Services\Webhooks;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\PlatformFeature;
use App\Enums\WebhookEvent;
use App\Http\Resources\PublicV1\PublicOrderResource;
use App\Jobs\SendWebhook;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\MerchantPayout;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Product;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Support\Str;

/**
 * Traduit les événements métier en webhooks pour les adresses abonnées du marchand.
 * Chaque envoi est journalisé (webhook_deliveries) puis expédié par le job SendWebhook.
 */
class WebhookDispatcher
{
    public function onOrderEvent(Order $order, OrderEvent $event): void
    {
        $events = [];

        $fields = [];

        if ($event->type === OrderEventType::Created) {
            $events[] = WebhookEvent::OrderCreated;
        } elseif ($event->to_status !== null && $event->to_status !== $event->from_status) {
            $events[] = WebhookEvent::OrderStatusChanged;

            if (in_array($event->to_status, [OrderStatus::DeliveryFailed, OrderStatus::Rescheduled], true)) {
                $events[] = WebhookEvent::OrderIncident;
            }
        } elseif (($fields = self::updatedFields($event)) !== []) {
            // Changement sans changement de statut (date, adresse, montant, retour demandé…) : l'application
            // du marchand doit aussi le savoir, sinon elle garde une information périmée
            $events[] = WebhookEvent::OrderUpdated;
        }

        if ($events === [] || ! $this->hasSubscribers($order->company_id, $order->merchant_id)) {
            return;
        }

        $order = $order->fresh(['deliveryZone', 'pickupZone', 'items', 'lastIncidentReason', 'attachments']);
        $data = [
            'order' => (new PublicOrderResource($order))->resolve(),
            'change' => [
                'type' => $event->type->value,
                'from_status' => $event->from_status?->value,
                'to_status' => $event->to_status?->value,
                'incident' => $event->incidentReason?->label,
                'rescheduled_to' => $event->rescheduled_to?->toDateString(),
                'note' => $event->visible_to_merchant ? $event->note : null,
                'fields' => $fields,
            ],
        ];

        foreach ($events as $type) {
            $this->dispatch($order->company_id, $order->merchant_id, $type->value, $data);
        }
    }

    /**
     * Champs modifiés par un événement qui ne change pas le statut (vide : rien à signaler au marchand).
     *
     * @return list<string>
     */
    public static function updatedFields(OrderEvent $event): array
    {
        return match (true) {
            // Course modifiée (marchand ou agence) : les champs changés, sauf les détails internes du prix
            $event->type === OrderEventType::Edited => array_values(array_diff(array_keys($event->meta['changes'] ?? []), ['recipient_id', 'pricing_details'])),
            $event->type === OrderEventType::ReturnRequested => ['return_requested'],
            // Journée de livraison fermée : livraison décalée au lendemain
            $event->type === OrderEventType::Note && isset($event->meta['postponed']) => ['delivery_scheduled_date'],
            // Nouvelle date pour une course déjà reportée
            $event->to_status === OrderStatus::Rescheduled && $event->from_status === OrderStatus::Rescheduled => ['delivery_scheduled_date'],
            $event->type === OrderEventType::Incident && $event->to_status === null => ['last_incident'],
            default => [],
        };
    }

    public function onPayoutPaid(MerchantPayout $payout): void
    {
        $this->dispatch($payout->company_id, $payout->merchant_id, WebhookEvent::PayoutPaid->value, ['payout' => [
            'reference' => $payout->reference,
            'net_amount' => $payout->net_amount,
            'total_collected' => $payout->total_collected,
            'total_fees' => $payout->total_fees,
            'method' => $payout->method?->value,
            'transaction_ref' => $payout->transaction_ref,
            'period_start' => $payout->period_start?->toDateString(),
            'period_end' => $payout->period_end?->toDateString(),
            'paid_at' => $payout->paid_at,
        ]]);
    }

    public function onLowStock(Product $product, int $available): void
    {
        $this->dispatch($product->company_id, $product->merchant_id, WebhookEvent::StockLow->value, ['product' => [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'available' => $available,
            'low_stock_threshold' => $product->low_stock_threshold,
        ]]);
    }

    /**
     * Envoie un événement à toutes les adresses actives du marchand qui l'écoutent.
     */
    public function dispatch(int $companyId, int $merchantId, string $event, array $data): void
    {
        if (! $this->apiIncluded($companyId, $merchantId)) {
            return;
        }

        WebhookSubscription::forCompany($companyId)
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookSubscription $s) => $s->listensTo($event))
            ->each(fn (WebhookSubscription $s) => $this->send($s, $event, $data));
    }

    /**
     * Crée l'envoi et le confie à la file (ou l'exécute tout de suite pour un test).
     */
    public function send(WebhookSubscription $subscription, string $event, array $data, bool $now = false): WebhookDelivery
    {
        $id = (string) Str::uuid();

        $delivery = WebhookDelivery::create([
            'company_id' => $subscription->company_id,
            'webhook_subscription_id' => $subscription->id,
            'event_id' => $id,
            'event' => $event,
            'payload' => [
                'id' => $id,
                'event' => $event,
                'created_at' => now()->toIso8601String(),
                'data' => $data,
            ],
        ]);

        $now ? (new SendWebhook($delivery->id, retry: false))->handle() : SendWebhook::dispatch($delivery->id)->afterCommit();

        return $delivery;
    }

    private function hasSubscribers(int $companyId, int $merchantId): bool
    {
        return $this->apiIncluded($companyId, $merchantId) && WebhookSubscription::forCompany($companyId)->where('merchant_id', $merchantId)->where('is_active', true)->exists();
    }

    // Webhooks : module « API et webhooks » de la formule de l'entreprise, ou e-commerçant relié par l'annuaire
    // (connexion en un clic, toutes formules)
    private function apiIncluded(int $companyId, int $merchantId): bool
    {
        return (bool) Company::query()->find($companyId)?->hasFeature(PlatformFeature::Api)
            || ApiKey::withoutGlobalScopes()->where('company_id', $companyId)->where('merchant_id', $merchantId)
                ->whereNotNull('client')->whereNull('revoked_at')->exists();
    }
}
