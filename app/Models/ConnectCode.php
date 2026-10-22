<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Code d'autorisation de la connexion en un clic : à usage unique, valable quelques minutes,
 * lié à l'application, à son adresse de retour et au défi PKCE.
 */
class ConnectCode extends Model
{
    protected $fillable = ['company_id', 'merchant_id', 'user_id', 'client_id', 'redirect_uri', 'code_hash', 'code_challenge', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class)->withoutGlobalScopes();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }
}
