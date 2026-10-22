<?php

namespace App\Services\Connect;

use RuntimeException;

/**
 * Erreur de l'échange du code (réponse 400 avec « error », comme OAuth 2).
 */
class ConnectException extends RuntimeException
{
    public function __construct(public readonly string $error, string $message)
    {
        parent::__construct($message);
    }
}
