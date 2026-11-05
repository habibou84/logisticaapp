<?php

namespace App\Services\Finance;

use App\Models\CashLog;
use App\Models\User;

/**
 * Journal de la caisse : chaque action sur l'argent (versement reçu ou annulé, avance, ajustement, paiement, paie)
 * est notée avec la personne qui l'a faite. Rien ne s'efface.
 */
class CashJournal
{
    /**
     * @param  array{courier_id?: ?int, merchant_id?: ?int, amount?: ?int, reference?: ?string}  $about
     */
    public function record(?User $by, int $companyId, string $action, string $description, array $about = []): CashLog
    {
        return CashLog::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'user_id' => $by?->id,
            'action' => $action,
            'description' => mb_substr($description, 0, 500),
            ...array_intersect_key($about, array_flip(['courier_id', 'merchant_id', 'amount', 'reference'])),
        ]);
    }
}
