<?php

namespace App\Http\Requests\PublicV1;

use App\Http\Requests\V1\UpdateOrderRequest;

/**
 * Modification d'une course par l'API publique : mêmes champs et mêmes règles que l'application du
 * marchand. La course est retrouvée par son code de suivi (droit vérifié par le contrôleur).
 */
class UpdatePublicOrderRequest extends UpdateOrderRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
