<?php

namespace App\Services\Messaging\Gateways;

use Jibiat\Socle\Sms\LogSmsGateway as SocleLog;

/**
 * Développement : le SMS est écrit dans le journal au lieu d'être envoyé (paquet commun habibou84/socle).
 */
class LogSmsGateway implements SmsGateway
{
    public function send(string $to, string $text): string
    {
        return (new SocleLog)->send($to, $text);
    }
}
