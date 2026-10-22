<?php

namespace App\Enums;

enum MessageChannel: string
{
    case WhatsApp = 'whatsapp';
    case Sms = 'sms';
    // Notification sur le téléphone de l'e-commerçant (application installée) : gratuite
    case Push = 'push';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => 'WhatsApp',
            self::Sms => 'SMS',
            self::Push => 'Notification',
        };
    }
}
