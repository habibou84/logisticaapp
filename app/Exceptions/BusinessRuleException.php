<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Règle métier non respectée (transition interdite, tarif manquant...).
 * Rendue en 422 avec le message, et le champ concerné si fourni.
 */
class BusinessRuleException extends Exception
{
    public function __construct(string $message, private readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => $this->field ? [$this->field => [$this->getMessage()]] : (object) [],
        ], 422);
    }
}
