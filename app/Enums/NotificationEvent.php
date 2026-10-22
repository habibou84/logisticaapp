<?php

namespace App\Enums;

/**
 * Événements pour lesquels un client peut recevoir un message WhatsApp.
 * Les notifications dans l'application sont toujours envoyées.
 */
enum NotificationEvent: string
{
    case OrderConfirmed = 'order.confirmed';
    case OrderPickedUp = 'order.picked_up';
    case OrderDelivered = 'order.delivered';
    case OrderIncident = 'order.incident';
    case PayoutPaid = 'payout.paid';
    case ReturnSlip = 'order.returned';
    // Clients sous formule (distribution, MAD)
    case InvoiceIssued = 'invoice.issued';
    case InvoiceReminder = 'invoice.reminder';
    case ForfaitUsage = 'forfait.usage';

    public function label(): string
    {
        return match ($this) {
            self::OrderConfirmed => 'Course validée',
            self::OrderPickedUp => 'Colis récupéré',
            self::OrderDelivered => 'Colis livré',
            self::OrderIncident => 'Incident (échec, report)',
            self::PayoutPaid => 'Reversement effectué',
            self::ReturnSlip => 'Retour de colis (bon de retour)',
            self::InvoiceIssued => 'Facture du mois émise',
            self::InvoiceReminder => 'Rappel de facture (échéance proche, retard)',
            self::ForfaitUsage => 'Forfait bientôt épuisé (80 %, puis 100 %)',
        };
    }

    /**
     * Activités concernées : un client sans formule ne voit pas les réglages de facture, un client sans
     * e-commerce pas ceux des reversements.
     *
     * @return list<Activity>
     */
    public function activities(): array
    {
        return match ($this) {
            self::PayoutPaid => [Activity::Ecommerce],
            self::InvoiceIssued, self::InvoiceReminder => [Activity::Distribution, Activity::Mad],
            self::ForfaitUsage => [Activity::Distribution, Activity::Mad],
            default => Activity::cases(),
        };
    }

    /**
     * Réglage par défaut : on évite d'inonder le client de messages de routine.
     */
    public function enabledByDefault(): bool
    {
        return match ($this) {
            self::OrderConfirmed, self::OrderPickedUp => false,
            default => true,
        };
    }
}
