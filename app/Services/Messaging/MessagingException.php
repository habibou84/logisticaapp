<?php

namespace App\Services\Messaging;

use RuntimeException;

/**
 * Échec d'envoi signalé par un fournisseur. « retryable » : une nouvelle
 * tentative a des chances d'aboutir (surcharge, panne passagère).
 */
class MessagingException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, public readonly ?string $providerCode = null)
    {
        parent::__construct($message);
    }
}
