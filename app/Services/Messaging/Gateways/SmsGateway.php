<?php

namespace App\Services\Messaging\Gateways;

use App\Services\Messaging\MessagingException;

interface SmsGateway
{
    /**
     * @throws MessagingException
     */
    public function send(string $to, string $text): string;
}
