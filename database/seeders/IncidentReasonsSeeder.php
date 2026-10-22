<?php

namespace Database\Seeders;

use App\Models\IncidentReason;
use Illuminate\Database\Seeder;

/**
 * Motifs d'incident communs (company_id null). Idempotent.
 */
class IncidentReasonsSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            ['unreachable', 'Destinataire injoignable', 'delivery', false, true, false],
            ['absent', 'Destinataire absent', 'delivery', false, true, false],
            ['wrong_address', 'Adresse introuvable ou erronée', 'delivery', false, true, false],
            ['postponed', 'Livraison reportée à la demande du client', 'delivery', true, false, false],
            ['no_money', 'Le client n\'a pas l\'argent', 'delivery', false, true, false],
            ['refused', 'Colis refusé par le client', 'delivery', false, true, true],
            ['damaged', 'Colis endommagé', 'both', false, false, false],
            ['merchant_unavailable', 'Marchand absent ou injoignable', 'pickup', false, false, false],
            ['package_not_ready', 'Colis pas prêt', 'pickup', false, false, false],
            ['other', 'Autre (préciser dans la note)', 'both', false, true, false],
        ];

        foreach ($reasons as $i => [$code, $label, $appliesTo, $requiresDate, $countsAsAttempt, $triggersReturn]) {
            IncidentReason::updateOrCreate(
                ['company_id' => null, 'code' => $code],
                [
                    'label' => $label,
                    'applies_to' => $appliesTo,
                    'requires_date' => $requiresDate,
                    'counts_as_attempt' => $countsAsAttempt,
                    'triggers_return' => $triggersReturn,
                    'sort_order' => $i,
                ],
            );
        }
    }
}
