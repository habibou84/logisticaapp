<?php

namespace App\Services\Messaging;

use App\Enums\LedgerEntryType;
use App\Enums\NotificationEvent;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\WhatsAppTemplate;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use App\Services\Orders\DeliveryDay;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Messages WhatsApp déclenchés par le journal d'une course : au marchand
 * (selon ses préférences) et au destinataire (colis en route, code de livraison).
 */
class OrderMessages
{
    public function __construct(private readonly Messenger $messenger) {}

    public function handle(Order $order, OrderEvent $event, ?User $actor): void
    {
        // Retour décidé par l'agence : le marchand est prévenu que son colis lui revient
        if ($event->type === OrderEventType::ReturnRequested && $actor?->merchant_id === null) {
            $order->loadMissing(['merchant', 'deliveryZone']);
            $this->incident($order, $event, $this->recipientLabel($order), 'le colis vous sera retourné');

            return;
        }

        if (! in_array($event->type, [OrderEventType::StatusChanged, OrderEventType::Incident], true)) {
            return;
        }

        $order->loadMissing(['merchant', 'deliveryZone', 'company']);
        $merchant = $order->merchant;
        $code = $order->tracking_code;
        $recipient = $this->recipientLabel($order);
        $actorIsMerchant = $actor?->merchant_id !== null;

        switch ($event->to_status) {
            case OrderStatus::Confirmed:
                if ($event->from_status === OrderStatus::Pending) {
                    if ($actorIsMerchant) {
                        break;
                    }
                    // Livraison un autre jour (date choisie ou journée fermée) : la date est dans le message
                    $later = $order->delivery_scheduled_date !== null && $order->delivery_scheduled_date->toDateString()
                        > CarbonImmutable::now($order->company->timezone ?: config('app.timezone'))->toDateString();
                    if ($later) {
                        $postponed = $event->meta['postponed'] ?? null;
                        $this->confirmedForDate($order, $postponed ?? 'date demandée', $postponed !== null);
                    } else {
                        $this->messenger->toMerchant($merchant, NotificationEvent::OrderConfirmed, WhatsAppTemplate::OrderConfirmed,
                            [$merchant->business_name, $code, $recipient, Money::format($order->totalFees())], $order);
                    }
                } else {
                    // Retour à « validée » depuis le ramassage : le ramassage a échoué
                    $this->incident($order, $event, $recipient, 'ramassage impossible');
                }
                break;

            case OrderStatus::PickedUp:
                $this->messenger->toMerchant($merchant, NotificationEvent::OrderPickedUp, WhatsAppTemplate::OrderPickedUp,
                    [$merchant->business_name, $code, $recipient], $order);
                break;

            case OrderStatus::OutForDelivery:
                // Expédition : le destinataire est prévenu au dépôt à la gare
                if ($order->is_shipping) {
                    break;
                }
                $this->messenger->toRecipient($order, 'order.out_for_delivery', WhatsAppTemplate::OutForDelivery, [
                    $order->recipient_name ?: 'cher client',
                    $merchant->business_name,
                    $order->cod_amount > 0 ? Money::format($order->cod_amount) : 'rien, déjà payé',
                    $order->delivery_code,
                    $order->company->url('/suivi/'.$code),
                ]);
                break;

            case OrderStatus::Delivered:
                if ($order->is_shipping) {
                    $this->shipped($order, $recipient);
                    break;
                }
                $this->messenger->toMerchant($merchant, NotificationEvent::OrderDelivered, WhatsAppTemplate::OrderDelivered,
                    [$merchant->business_name, $code, $recipient, Money::format((int) $order->collected_amount)], $order);
                break;

            case OrderStatus::DeliveryFailed:
                $this->incident($order, $event, $recipient, 'livraison impossible');
                break;

            case OrderStatus::Lost:
                $compensation = (int) MerchantLedgerEntry::where('order_id', $order->id)->where('type', LedgerEntryType::LostCompensation->value)->sum('amount');
                $this->incident($order, $event, $recipient, 'colis perdu, nous vous présentons nos excuses'
                    .($compensation > 0 ? '. Indemnité de '.Money::format($compensation).' portée à votre compte' : ''));
                break;

            case OrderStatus::Rescheduled:
                if (! $actorIsMerchant) {
                    $date = $event->rescheduled_to?->format('d/m/Y');
                    $this->incident($order, $event, $recipient, 'livraison reportée'.($date ? " au {$date}" : ''));
                }
                break;

            default:
                break;
        }
    }

    private function shipped(Order $order, string $recipient): void
    {
        $merchant = $order->merchant;
        $ticket = $order->shipping_reference ?: 'sans numéro';

        $this->messenger->toMerchant($merchant, NotificationEvent::OrderDelivered, WhatsAppTemplate::Shipped, [
            $merchant->business_name, $order->tracking_code, $recipient, $order->shipping_carrier, $ticket, Money::format((int) $order->shipping_fee),
        ], $order);

        $this->messenger->toRecipient($order, 'order.shipped', WhatsAppTemplate::ShippedRecipient, [
            $order->recipient_name ?: 'cher client', $merchant->business_name, $order->shipping_carrier, $ticket,
        ]);
    }

    private function incident(Order $order, OrderEvent $event, string $recipient, string $what): void
    {
        $details = collect([$what, $event->incidentReason?->label, $event->note])->filter()->implode(', ');

        $this->messenger->toMerchant($order->merchant, NotificationEvent::OrderIncident, WhatsAppTemplate::DeliveryIncident, [
            $order->merchant->business_name,
            $order->tracking_code,
            $recipient,
            $details,
            $order->company->url('/marchand/courses/'.$order->id),
        ], $order);
    }

    /**
     * Course validée pour une livraison à une date précise ($reason : « date demandée »,
     * « livraisons du jour closes depuis 16:00 »…).
     */
    public function confirmedForDate(Order $order, string $reason, bool $postponed = false): void
    {
        $order->loadMissing(['merchant', 'deliveryZone']);
        $merchant = $order->merchant;

        // Date décalée par l'agence : le marchand est prévenu comme pour un report (actif par défaut),
        // la simple validation reste un message de routine (désactivé par défaut)
        $event = $postponed ? NotificationEvent::OrderIncident : NotificationEvent::OrderConfirmed;

        $this->messenger->toMerchant($merchant, $event, WhatsAppTemplate::OrderConfirmedForDate, [
            $merchant->business_name,
            $order->tracking_code,
            $this->recipientLabel($order),
            DeliveryDay::longDate($order->delivery_scheduled_date),
            $reason,
            Money::format($order->totalFees()),
        ], $order);
    }

    private function recipientLabel(Order $order): string
    {
        $name = $order->recipient_name ?: $order->recipient_phone;

        return $order->deliveryZone ? "{$name} ({$order->deliveryZone->name})" : (string) $name;
    }
}
