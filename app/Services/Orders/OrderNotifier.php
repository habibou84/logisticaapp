<?php

namespace App\Services\Orders;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use App\Notifications\OrderAlert;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Décide qui alerter pour chaque action sur une course. L'auteur de l'action
 * n'est jamais notifié de sa propre action.
 */
class OrderNotifier
{
    public function notify(Order $order, OrderEvent $event, ?User $actor): void
    {
        $order->loadMissing('merchant');
        $code = $order->tracking_code;
        $merchantName = $order->merchant->business_name;
        $reason = $event->incidentReason?->label;
        $note = $event->note ? ' « '.$event->note.' »' : '';
        $actorIsMerchant = $actor?->merchant_id !== null;

        $toMerchant = null;
        $toStaff = null;

        switch ($event->type) {
            case OrderEventType::Created:
                $toStaff = ['new_order', "Nouvelle course {$code}", "{$merchantName} → {$order->deliveryZone?->name}"];
                break;

            case OrderEventType::Assigned:
                $courierUser = User::find($event->meta['courier_user_id'] ?? null);
                if ($courierUser) {
                    // Acceptée d'office : le livreur n'a rien à répondre, il y va
                    $this->send(collect([$courierUser]), $actor, $order, 'new_mission',
                        'Nouvelle mission : '.($event->meta['type_label'] ?? ''), "{$code} · {$merchantName}".(! empty($event->meta['accepted']) ? ' · confiée d\'office' : ''),
                        extra: ['assignment_id' => $event->assignment_id]);
                }
                break;

            case OrderEventType::Unassigned:
                // Mission retirée par le dispatch : le livreur est prévenu de ne pas y aller
                $courierUser = User::find($event->meta['courier_user_id'] ?? null);
                if ($courierUser) {
                    $this->send(collect([$courierUser]), $actor, $order, 'mission_removed',
                        'Mission retirée : '.($event->meta['type_label'] ?? ''), "{$code} · {$merchantName} : n'y allez pas.".$note);
                }
                break;

            case OrderEventType::AssignmentRefused:
                $toStaff = ! empty($event->meta['impediment'])
                    ? ['mission_refused', "Empêchement sur {$code}", ($event->actor_name ?? 'Le livreur').' ne peut pas faire la mission.'.$note]
                    : ['mission_refused', "Mission refusée {$code}", ($event->actor_name ?? 'Le livreur').' a refusé la mission.'.$note];
                break;

            case OrderEventType::Note:
                if ($actorIsMerchant) {
                    $toStaff = ['note', "Note du marchand sur {$code}", $merchantName.' :'.$note];
                } else {
                    if ($event->visible_to_merchant) {
                        $toMerchant = ['note', "Note sur votre colis {$code}", trim(($event->actor_name ?? '').' :'.$note)];
                    }
                    // Note d'un livreur : toujours signalée au dispatch, même invisible pour le marchand
                    if ($actor?->isCourier()) {
                        $toStaff = ['note', "Note du livreur sur {$code}", trim(($event->actor_name ?? '').' :'.$note)];
                    }
                }
                break;

            case OrderEventType::ExpenseAdded:
                if ($event->visible_to_merchant) {
                    $toMerchant = ['expense', "Frais sur votre colis {$code}", (string) $event->note];
                }
                if ($actor?->isCourier()) {
                    $toStaff = ['expense', "Frais déclarés sur {$code}", trim(($event->actor_name ?? '').' : '.$event->note)];
                }
                break;

            case OrderEventType::ReturnRequested:
                $toStaff = $actorIsMerchant
                    ? ['return_requested', "Décision du marchand {$code}", $merchantName.' demande le retour du colis.'.$note]
                    : ['return_requested', "Retour demandé pour {$code}", ($event->actor_name ?: 'L\'agence').' a demandé le retour du colis.'.$note];
                break;

            case OrderEventType::StatusChanged:
            case OrderEventType::Incident:
                [$toMerchant, $toStaff] = $this->forStatus($order, $event, $actorIsMerchant, $reason, $note);
                break;

            default:
                break;
        }

        // Course envoyée par une application qui prévient elle-même le marchand (Jibiat Marchand) : pas de doublon
        if ($toMerchant && ! $order->merchant_alerts_muted) {
            $this->send($this->merchantUsers($order), $actor, $order, ...$toMerchant);
        }

        if ($toStaff) {
            $this->send($this->dispatchers($order), $actor, $order, ...$toStaff, extra: $this->fieldReport($event, $actor));
        }
    }

    /**
     * @return array{0: ?array, 1: ?array}
     */
    private function forStatus(Order $order, OrderEvent $event, bool $actorIsMerchant, ?string $reason, string $note): array
    {
        $code = $order->tracking_code;
        $why = $reason ? " Motif : {$reason}." : '';
        $date = $event->rescheduled_to?->format('d/m/Y');

        return match ($event->to_status) {
            OrderStatus::PickedUp => [['picked_up', "Colis {$code} récupéré", 'Votre colis a été récupéré par notre livreur.'], null],
            OrderStatus::OutForDelivery => [['out_for_delivery', "Colis {$code} en chemin", 'Votre colis est en cours de livraison.'], null],
            OrderStatus::Delivered => $order->is_shipping
                ? [['delivered', "Colis {$code} expédié", "Déposé chez {$order->shipping_carrier}. Frais d'expédition : ".Money::format((int) $order->shipping_fee).'.'], null]
                : [['delivered', "Colis {$code} livré", 'Votre colis a été livré.'], null],
            OrderStatus::DeliveryFailed => [
                ['incident', "Échec de livraison {$code}", "La livraison n'a pas pu être effectuée.{$why}{$note}"],
                ['incident', "Échec de livraison {$code}", trim("{$reason}{$note}")],
            ],
            OrderStatus::Rescheduled => [
                $actorIsMerchant ? null : ['rescheduled', "Livraison reportée {$code}", "Livraison reportée au {$date}.{$why}{$note}"],
                $actorIsMerchant
                    ? ['rescheduled', "Décision du marchand {$code}", "{$order->merchant->business_name} : relivrer le {$date}.{$note}"]
                    : ['rescheduled', "Livraison reportée {$code}", "Reportée au {$date}.{$why}{$note}"],
            ],
            OrderStatus::Confirmed => $event->from_status === OrderStatus::Pending
                ? [['confirmed', "Course {$code} validée", 'Votre course a été validée.'], null]
                : [null, ['pickup_failed', "Échec du ramassage {$code}", trim("{$reason}{$note}")]],
            OrderStatus::Returned => [['returned', "Colis {$code} retourné", 'Le colis vous a été retourné.'], null],
            OrderStatus::Rejected => [['rejected', "Course {$code} refusée", 'La course a été refusée par l\'entreprise de livraison.'.$note], null],
            OrderStatus::Cancelled => $actorIsMerchant
                ? [null, ['cancelled', "Course {$code} annulée", 'Le marchand a annulé la course.'.$note]]
                : [['cancelled', "Course {$code} annulée", 'La course a été annulée.'.$note], null],
            default => [null, null],
        };
    }

    /**
     * @return Collection<int, User>
     */
    private function merchantUsers(Order $order): Collection
    {
        return User::where('merchant_id', $order->merchant_id)->where('status', 'active')->get();
    }

    /**
     * Administrateurs et dispatchers de l'entreprise.
     *
     * @return Collection<int, User>
     */
    private function dispatchers(Order $order): Collection
    {
        return User::forCompany($order->company_id)
            ->where('status', 'active')
            ->role([Role::Admin->value, Role::Dispatcher->value])
            ->get();
    }

    /**
     * @param  Collection<int, User>  $users
     */
    /**
     * Remontée terrain d'un livreur : signalée comme telle (son, journal « Remontées terrain »).
     *
     * @return array<string, mixed>
     */
    private function fieldReport(OrderEvent $event, ?User $actor): array
    {
        if (! $actor?->isCourier() || ! OrderEvent::whereKey($event->id)->fieldReports()->exists()) {
            return [];
        }

        return ['field' => true, 'severity' => $event->fieldKind() === 'note' ? 'note' : 'alert', 'event_id' => $event->id];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function send(Collection $users, ?User $actor, Order $order, string $kind, string $title, string $body, array $extra = []): void
    {
        $users = $users->reject(fn (User $u) => $actor !== null && $u->is($actor));

        if ($users->isNotEmpty()) {
            Notification::send($users, new OrderAlert($order, $kind, $title, $body, $extra));
        }
    }
}
