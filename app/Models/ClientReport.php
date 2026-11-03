<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rapport mensuel d'un client sous contrat (distribution, MAD) : volumes, délais, accusés, départements, échecs.
 * Préparé le 1er du mois en brouillon, envoyé par l'équipe après relecture (avec un mot facultatif).
 */
class ClientReport extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'merchant_id', 'period', 'status', 'data', 'note', 'sent_at', 'sent_by'];

    protected function casts(): array
    {
        return ['period' => 'date:Y-m-d', 'data' => 'array', 'sent_at' => 'datetime'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
