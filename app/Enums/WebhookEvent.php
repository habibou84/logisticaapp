<?php

namespace App\Enums;

enum WebhookEvent: string
{
    case OrderCreated = 'order.created';
    case OrderStatusChanged = 'order.status_changed';
    case OrderIncident = 'order.incident';
    case OrderUpdated = 'order.updated';
    case PayoutPaid = 'payout.paid';
    case StockLow = 'stock.low';

    public function label(): string
    {
        return match ($this) {
            self::OrderCreated => 'Course créée',
            self::OrderStatusChanged => 'Changement de statut',
            self::OrderIncident => 'Incident de livraison (échec, report)',
            self::OrderUpdated => 'Course modifiée (date, adresse, montant, décision, retour demandé)',
            self::PayoutPaid => 'Reversement payé',
            self::StockLow => 'Stock bas',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $e) => $e->value, self::cases());
    }
}
