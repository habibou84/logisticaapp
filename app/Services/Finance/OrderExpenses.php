<?php

namespace App\Services\Finance;

use App\Enums\ExpenseType;
use App\Enums\OrderEventType;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\OrderExpense;
use App\Models\User;
use App\Services\Orders\OrderJournal;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Frais engagés pour une course. Facturés au marchand, ils sont inscrits à son grand
 * livre (et donc déduits de son point) ; avancés par un livreur, ils sont déduits de
 * son prochain versement à la caisse.
 */
class OrderExpenses
{
    public function __construct(private readonly OrderJournal $journal) {}

    /**
     * @param  array{type: ExpenseType|string, amount: int, label?: ?string, paid_by?: string, courier_id?: ?int, billed_to?: string}  $data
     */
    public function add(User $actor, Order $order, array $data, bool $journal = true): OrderExpense
    {
        $type = $data['type'] instanceof ExpenseType ? $data['type'] : ExpenseType::from($data['type']);
        $paidBy = $data['paid_by'] ?? 'courier';
        $courierId = $paidBy === 'courier' ? ($data['courier_id'] ?? null) : null;

        if ($paidBy === 'courier' && $courierId === null) {
            throw new BusinessRuleException('Indiquez le livreur qui a payé.', 'courier_id');
        }

        if ($type === ExpenseType::Other && blank($data['label'] ?? null)) {
            throw new BusinessRuleException('Précisez la nature des frais.', 'label');
        }

        return DB::transaction(function () use ($actor, $order, $data, $type, $paidBy, $courierId, $journal) {
            $expense = new OrderExpense([
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'type' => $type,
                'label' => filled($data['label'] ?? null) ? trim($data['label']) : null,
                'amount' => (int) $data['amount'],
                'paid_by' => $paidBy,
                'courier_id' => $courierId,
                'billed_to' => $data['billed_to'] ?? 'merchant',
                'created_by' => $actor->id,
            ]);

            if ($expense->billed_to === 'merchant' && $expense->amount > 0) {
                $expense->ledger_entry_id = MerchantLedgerEntry::create([
                    'company_id' => $order->company_id,
                    'merchant_id' => $order->merchant_id,
                    'type' => $type->ledgerType(),
                    'amount' => -$expense->amount,
                    'order_id' => $order->id,
                    'description' => $expense->description(),
                    'created_by' => $actor->id,
                ])->id;
            }

            $expense->save();

            if ($journal) {
                $this->journal->record($order, $actor, OrderEventType::ExpenseAdded, [
                    'note' => $expense->description().' : '.Money::format($expense->amount),
                    'visible_to_merchant' => $expense->billed_to === 'merchant',
                    'meta' => $this->meta($expense),
                ]);
            }

            return $expense;
        });
    }

    /**
     * Annule des frais saisis par erreur : contre-écriture au grand livre du marchand.
     */
    public function cancel(User $actor, OrderExpense $expense): OrderExpense
    {
        return DB::transaction(function () use ($actor, $expense) {
            $expense = OrderExpense::query()->lockForUpdate()->findOrFail($expense->id);

            if ($expense->cancelled_at !== null) {
                throw new BusinessRuleException('Ces frais sont déjà annulés.');
            }

            if ($expense->remittance_id !== null) {
                throw new BusinessRuleException('Le livreur a déjà été remboursé de ces frais : corrigez par un ajustement.');
            }

            $entry = $expense->ledgerEntry;
            if ($entry?->payout_id !== null) {
                throw new BusinessRuleException('Ces frais figurent déjà dans un relevé : corrigez par un ajustement.');
            }

            if ($entry) {
                MerchantLedgerEntry::create([
                    'company_id' => $entry->company_id,
                    'merchant_id' => $entry->merchant_id,
                    'type' => $entry->type,
                    'amount' => -$entry->amount,
                    'order_id' => $entry->order_id,
                    'description' => 'Annulation : '.$entry->description,
                    'created_by' => $actor->id,
                ]);
            }

            $expense->forceFill(['cancelled_at' => now(), 'cancelled_by' => $actor->id])->save();

            $this->journal->record($expense->order, $actor, OrderEventType::ExpenseCancelled, [
                'note' => 'Annulé : '.$expense->description().' ('.Money::format($expense->amount).')',
                'visible_to_merchant' => $expense->billed_to === 'merchant',
                'meta' => $this->meta($expense),
            ]);

            return $expense;
        });
    }

    /**
     * Livreur qui a payé, par défaut : celui de la mission en cours.
     */
    public static function defaultCourier(Order $order): ?Courier
    {
        return $order->assignments()->latest('id')->first()?->courier;
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(OrderExpense $expense): array
    {
        return [
            'expense_id' => $expense->id,
            'expense_type' => $expense->type->value,
            'amount' => $expense->amount,
            'paid_by' => $expense->paid_by,
            'billed_to' => $expense->billed_to,
        ];
    }
}
