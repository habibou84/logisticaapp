<?php

namespace App\Models;

use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Réponse mémorisée d'une requête POST de l'API publique, rejouée à l'identique
 * si la même clé d'idempotence revient (relance réseau). Conservée 24 heures.
 */
class IdempotencyKey extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $fillable = ['api_key_id', 'key', 'request_hash', 'response_code', 'response_body'];

    protected function casts(): array
    {
        return ['response_code' => 'integer'];
    }

    public function prunable()
    {
        return static::where('created_at', '<', now()->subDay());
    }
}
