<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Action d'un livreur faite sans réseau puis envoyée au retour de la connexion : appliquée une seule
 * fois (identifiant tiré par le téléphone), avec son résultat (appliquée ou refusée, et pourquoi).
 */
class OfflineAction extends Model
{
    use BelongsToCompany;

    public const OK = 'ok';

    public const REJECTED = 'rejected';

    protected $fillable = ['company_id', 'user_id', 'client_id', 'order_id', 'kind', 'status', 'message', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
