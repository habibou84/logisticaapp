<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case PickupAssigned = 'pickup_assigned';
    case PickupInProgress = 'pickup_in_progress';
    case PickedUp = 'picked_up';
    case AtHub = 'at_hub';
    case DeliveryAssigned = 'delivery_assigned';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case DeliveryFailed = 'delivery_failed';
    case Rescheduled = 'rescheduled';
    case ReturnAssigned = 'return_assigned';
    case Returning = 'returning';
    case Returned = 'returned';
    case Cancelled = 'cancelled';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente de validation',
            self::Confirmed => 'Validée',
            self::Rejected => 'Refusée',
            self::PickupAssigned => 'Ramassage assigné',
            self::PickupInProgress => 'En route pour le ramassage',
            self::PickedUp => 'Récupéré',
            self::AtHub => 'Au dépôt',
            self::DeliveryAssigned => 'Livraison assignée',
            self::OutForDelivery => 'En chemin',
            self::Delivered => 'Livré',
            self::DeliveryFailed => 'Échec de livraison',
            self::Rescheduled => 'Reporté',
            self::ReturnAssigned => 'Retour assigné',
            self::Returning => 'Retour en cours',
            self::Returned => 'Retourné',
            self::Cancelled => 'Annulée',
            self::Lost => 'Perdu',
        };
    }

    /**
     * Transitions autorisées depuis ce statut. Les assignations (ramassage,
     * livraison, retour) passent par OrderDispatcher et non par cette table.
     * « Perdu » passe uniquement par la déclaration de perte (OrderLosses).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Rejected, self::Cancelled],
            // Commande d'entrepôt : « Préparée » la place au dépôt
            self::Confirmed => [self::Cancelled, self::AtHub],
            // Échec au ramassage : retour à « Validée » pour réassignation
            self::PickupAssigned => [self::PickupInProgress, self::PickedUp, self::Confirmed, self::Cancelled],
            self::PickupInProgress => [self::PickedUp, self::Confirmed, self::Cancelled],
            self::PickedUp => [self::AtHub, self::OutForDelivery, self::Lost],
            // « Retourné » direct = remis en stock à l'entrepôt (commande d'entrepôt)
            self::AtHub => [self::OutForDelivery, self::Rescheduled, self::Returned, self::Lost],
            self::DeliveryAssigned => [self::OutForDelivery, self::AtHub, self::Lost],
            self::OutForDelivery => [self::Delivered, self::DeliveryFailed, self::Rescheduled, self::Lost],
            self::DeliveryFailed => [self::Rescheduled, self::OutForDelivery, self::AtHub, self::Returned, self::Lost],
            // Rescheduled → Rescheduled : nouvelle date décidée par le dispatch
            self::Rescheduled => [self::OutForDelivery, self::AtHub, self::Rescheduled, self::Returned, self::Lost],
            self::ReturnAssigned => [self::Returning, self::Returned, self::Lost],
            self::Returning => [self::Returned, self::Lost],
            self::Delivered, self::Returned, self::Cancelled, self::Rejected, self::Lost => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Returned, self::Cancelled, self::Rejected, self::Lost], true);
    }

    /**
     * Le colis n'a pas encore été récupéré chez le marchand.
     */
    public function isBeforePickup(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed, self::PickupAssigned, self::PickupInProgress], true);
    }

    /**
     * Le colis est entre les mains de l'entreprise et attend une (nouvelle) livraison.
     */
    public function awaitsDeliveryAssignment(): bool
    {
        return in_array($this, [self::PickedUp, self::AtHub, self::DeliveryFailed, self::Rescheduled], true);
    }

    /**
     * @return list<string>
     */
    public static function values(array $statuses): array
    {
        return array_map(fn (self $s) => $s->value, $statuses);
    }
}
