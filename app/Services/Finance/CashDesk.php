<?php

namespace App\Services\Finance;

use App\Enums\EarningType;
use App\Enums\LedgerEntryType;
use App\Exceptions\BusinessRuleException;
use App\Models\CashClosing;
use App\Models\CashCollection;
use App\Models\Courier;
use App\Models\CourierAdvance;
use App\Models\CourierEarning;
use App\Models\CourierRemittance;
use App\Models\MerchantLedgerEntry;
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

    /**
     * Annule un versement reçu par erreur, le jour même : l'argent, les avances et les frais reviennent chez le
     * livreur, le manque retenu sur sa paie est retiré. Les colis rendus restent au dépôt. Refusé si la paie du
     * manque est déjà préparée, ou si un e-commerçant a déjà été payé avec cet argent.
     */
    public function cancel(User $actor, CourierRemittance $remittance, string $reason): CourierRemittance
    {
        if ($remittance->cancelled_at !== null) {
            throw new BusinessRuleException('Ce versement est déjà annulé.', 'remittance');
        }
        if (! $remittance->received_at->isToday()) {
            throw new BusinessRuleException('Un versement ne s\'annule que le jour même. Pour corriger, faites une prime ou une retenue.', 'remittance');
        }
        if (self::closedAfter($remittance->company_id, $remittance->received_at)) {
            throw new BusinessRuleException('La caisse a déjà été clôturée avec ce versement. Pour corriger, faites une prime ou une retenue.', 'remittance');
        }

        return DB::transaction(function () use ($actor, $remittance, $reason) {
            $collections = CashCollection::where('remittance_id', $remittance->id)->lockForUpdate()->get();
            $paidOut = MerchantLedgerEntry::whereIn('order_id', $collections->pluck('order_id'))
                ->where('type', LedgerEntryType::CodCredit)->whereNotNull('payout_id')->exists();
            if ($paidOut) {
                throw new BusinessRuleException('Un e-commerçant a déjà un paiement préparé ou fait avec cet argent : annulez d\'abord ce paiement.', 'remittance');
            }
            $shortfall = CourierEarning::where('remittance_id', $remittance->id)->lockForUpdate()->get();
            if ($shortfall->contains(fn (CourierEarning $e) => $e->payout_id !== null)) {
                throw new BusinessRuleException('Le manque de caisse est déjà sur une fiche de paie : annulez d\'abord la fiche.', 'remittance');
            }

            CashCollection::whereIn('id', $collections->modelKeys())->update(['remittance_id' => null]);
            CourierAdvance::where('remittance_id', $remittance->id)->update(['remittance_id' => null]);
            OrderExpense::where('remittance_id', $remittance->id)->update(['remittance_id' => null]);
            CourierEarning::whereIn('id', $shortfall->modelKeys())->delete();

            $remittance->forceFill(['cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancel_reason' => $reason])->save();

            return $remittance;
        });
    }

    /** Une clôture de caisse a compté ce mouvement : l'annuler fausserait la clôture */
    private static function closedAfter(int $companyId, \DateTimeInterface $at): bool
    {
        return CashClosing::withoutGlobalScopes()->where('company_id', $companyId)->where('closed_at', '>=', $at)->exists();
    }

    /** Annule une avance donnée par erreur, le jour même, tant qu'elle n'est pas réglée au versement ni comptée dans une clôture */
    public function cancelAdvance(User $actor, CourierAdvance $advance, string $reason): CourierAdvance
    {
        if ($advance->cancelled_at !== null || $advance->remittance_id !== null || $advance->courier_payout_id !== null) {
            throw new BusinessRuleException('Cette avance ne peut plus être annulée.', 'advance');
        }
        if (! $advance->given_at->isToday()) {
            throw new BusinessRuleException('Une avance ne s\'annule que le jour même.', 'advance');
        }
        if (self::closedAfter($advance->company_id, $advance->given_at)) {
            throw new BusinessRuleException('La caisse a déjà été clôturée avec cette avance : elle sera réglée au prochain versement du livreur.', 'advance');
        }
        $advance->forceFill(['cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancel_reason' => $reason])->save();

        return $advance;
    }
}
