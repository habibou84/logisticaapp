<?php

namespace App\Services\Finance;

use App\Enums\EarningType;
use App\Exceptions\BusinessRuleException;
use App\Models\CashCollection;
use App\Models\Courier;
use App\Models\CourierAdvance;
use App\Models\CourierEarning;
use App\Models\CourierRemittance;
use App\Models\OrderExpense;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Caisse : réception de l'argent encaissé par les livreurs, avances remises
 * avant une mission et remboursement des frais qu'ils ont payés.
 */
class CashDesk
{
    /**
     * Enregistre le versement d'un livreur. Sans liste d'encaissements, tout ce qu'il
     * a en main est soldé. Un manque est retenu sur sa prochaine paie.
     *
     * @param  list<int>|null  $collectionIds
     */
    public function remit(User $actor, Courier $courier, int $amountReceived, ?array $collectionIds = null, ?string $notes = null): CourierRemittance
    {
        return DB::transaction(function () use ($actor, $courier, $amountReceived, $collectionIds, $notes) {
            $collections = CashCollection::query()
                ->where('courier_id', $courier->id)
                ->inCourierHands()
                ->when($collectionIds !== null, fn ($q) => $q->whereIn('id', $collectionIds))
                ->lockForUpdate()
                ->get();

            if ($collectionIds !== null && $collections->count() !== count(array_unique($collectionIds))) {
                throw new BusinessRuleException('Certains encaissements sont introuvables ou déjà versés.', 'collection_ids');
            }

            // Avances reçues et frais payés pour les courses : toujours réglés au versement
            $advances = $courier->advances()->unsettled()->lockForUpdate()->get();
            $expenses = $courier->expenses()->owedToCourier()->lockForUpdate()->get();

            if ($collections->isEmpty() && $advances->isEmpty() && $expenses->isEmpty()) {
                throw new BusinessRuleException('Ce livreur n\'a aucun encaissement à verser.', 'courier_id');
            }

            // Négatif : la caisse rembourse au livreur des frais qu'il a avancés
            $expected = $collections->sum('amount_collected') + $advances->sum('amount') - $expenses->sum('amount');

            $remittance = CourierRemittance::create([
                'company_id' => $courier->company_id,
                'courier_id' => $courier->id,
                'amount_expected' => $expected,
                'amount_received' => $amountReceived,
                'difference' => $amountReceived - $expected,
                'received_by' => $actor->id,
                'received_at' => now(),
                'notes' => $notes,
            ]);

            CashCollection::whereIn('id', $collections->modelKeys())->update(['remittance_id' => $remittance->id]);
            CourierAdvance::whereIn('id', $advances->modelKeys())->update(['remittance_id' => $remittance->id]);
            OrderExpense::whereIn('id', $expenses->modelKeys())->update(['remittance_id' => $remittance->id]);

            if ($remittance->difference < 0) {
                CourierEarning::create([
                    'company_id' => $courier->company_id,
                    'courier_id' => $courier->id,
                    'type' => EarningType::Shortfall,
                    'amount' => $remittance->difference,
                    'remittance_id' => $remittance->id,
                    'description' => 'Manque de caisse du '.now()->format('d/m/Y'),
                    'created_by' => $actor->id,
                ]);
            }

            return $remittance;
        });
    }
}
