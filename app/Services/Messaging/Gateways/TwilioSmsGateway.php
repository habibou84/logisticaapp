<?php

namespace App\Services\Messaging\Gateways;

use App\Services\Messaging\MessagingException;
use Jibiat\Socle\Sms\SmsException;
use Jibiat\Socle\Sms\TwilioSmsGateway as SocleTwilio;

/**
 * Envoi par Twilio, assuré par le paquet commun habibou84/socle ; les erreurs deviennent des MessagingException
 * (réessayables si la panne est passagère).
 */
class TwilioSmsGateway implements SmsGateway
{
    public function send(string $to, string $text): string
    {
        ['sid' => $sid, 'token' => $token, 'from' => $from] = config('messaging.sms.twilio');

        if (blank($sid) || blank($token) || blank($from)) {
            throw new MessagingException('SMS non configuré (TWILIO_SID, TWILIO_TOKEN, TWILIO_FROM).');
        }

        try {
            return (new SocleTwilio($sid, $token, $from))->send($to, $text);
        } catch (SmsException $e) {
            throw new MessagingException('Twilio : '.$e->getMessage(), retryable: $e->retryable);
        }
    }
}
