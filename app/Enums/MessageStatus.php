<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'En attente',
            self::Sent => 'Envoyé',
            self::Delivered => 'Reçu',
            self::Read => 'Lu',
            self::Failed => 'Échec',
        };
    }

    /**
     * Ordre de progression : un accusé arrivé en retard (« envoyé » après « lu »)
     * ne fait pas reculer le statut.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Sent => 1,
            self::Delivered => 2,
            self::Read => 3,
            self::Failed => 4,
        };
    }
}
