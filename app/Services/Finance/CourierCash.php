<?php

namespace App\Services\Finance;

use App\Models\CashCollection;
use App\Models\Courier;
use App\Models\CourierAdvance;
use App\Models\OrderExpense;

/**
 * Ce qu'un livreur doit à la caisse : l'argent encaissé, plus les avances reçues,
 * moins les frais qu'il a payés pour les courses et la paie qu'il a gardée sur l'encaissé.
 * Négatif : la caisse lui doit de l'argent.
 */
class CourierCash
{
    /**
     * @return array{collected: int, advances: int, expenses: int, pay_kept: int, due: int}
     */
    public function balance(Courier $courier): array
    {
        $collected = (int) $courier->collections()->inCourierHands()->sum('amount_collected');
        $advances = (int) $courier->advances()->unsettled()->cash()->sum('amount');
        $expenses = (int) $courier->expenses()->owedToCourier()->sum('amount');
        // Paie que le livreur garde sur l'argent encaissé (montants négatifs)
        $payKept = -(int) $courier->advances()->unsettled()->whereNotNull('courier_payout_id')->sum('amount');

        return [
            'collected' => $collected,
            'advances' => $advances,
            'expenses' => $expenses,
            'pay_kept' => $payKept,
            'due' => $collected + $advances - $expenses - $payKept,
        ];
    }

    /**
     * Soldes de tous les livreurs de l'entreprise, en trois requêtes groupées.
     *
     * @return array<int, array{collected: int, advances: int, expenses: int, due: int, items: int, oldest: ?string}>
     */
    public function balances(): array
    {
        $collections = CashCollection::inCourierHands()->groupBy('courier_id')
            ->selectRaw('courier_id, SUM(amount_collected) AS total, COUNT(*) AS n, MIN(collected_at) AS oldest')
            ->get()->keyBy('courier_id');
        $advances = CourierAdvance::unsettled()->groupBy('courier_id')
            ->selectRaw('courier_id, SUM(amount) AS total, COUNT(*) AS n')->get()->keyBy('courier_id');
        $expenses = OrderExpense::owedToCourier()->groupBy('courier_id')
            ->selectRaw('courier_id, SUM(amount) AS total, COUNT(*) AS n')->get()->keyBy('courier_id');

        $ids = $collections->keys()->merge($advances->keys())->merge($expenses->keys())->unique();

        return $ids->mapWithKeys(function ($id) use ($collections, $advances, $expenses) {
            $collected = (int) ($collections[$id]->total ?? 0);
            $advanced = (int) ($advances[$id]->total ?? 0);
            $spent = (int) ($expenses[$id]->total ?? 0);

            return [$id => [
                'collected' => $collected,
                'advances' => $advanced,
                'expenses' => $spent,
                'due' => $collected + $advanced - $spent,
                'items' => (int) ($collections[$id]->n ?? 0) + (int) ($advances[$id]->n ?? 0) + (int) ($expenses[$id]->n ?? 0),
                'oldest' => $collections[$id]->oldest ?? null,
            ]];
        })->all();
    }
}
