<?php

namespace App\Services\Stock;

use App\Enums\LedgerEntryType;
use App\Enums\StorageBillingType;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StorageCharge;
use App\Models\StorageContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Facturation mensuelle du stockage : une écriture « Frais de stockage » au grand
 * livre de chaque marchand, déduite de son prochain reversement. Une seule
 * facturation par contrat et par mois : relancer la commande ne double rien.
 */
class StorageBilling
{
    /**
     * @return int nombre de contrats facturés
     */
    public function bill(Carbon $month, ?int $companyId = null): int
    {
        $start = $month->copy()->startOfMonth()->startOfDay();
        $end = $month->copy()->endOfMonth()->startOfDay();
        $count = 0;

        StorageContract::withoutGlobalScopes()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereDate('starts_on', '<=', $end)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start))
            ->whereDoesntHave('charges', fn ($q) => $q->withoutGlobalScopes()->whereDate('period', $start))
            ->with('hub')
            ->orderBy('id')
            ->each(function (StorageContract $contract) use ($start, $end, &$count) {
                $this->billContract($contract, $start, $end);
                $count++;
            });

        return $count;
    }

    public function billContract(StorageContract $contract, Carbon $monthStart, Carbon $monthEnd): StorageCharge
    {
        $from = $contract->starts_on->greaterThan($monthStart) ? $contract->starts_on->copy()->startOfDay() : $monthStart->copy();
        $to = $contract->ends_on && $contract->ends_on->lessThan($monthEnd) ? $contract->ends_on->copy()->startOfDay() : $monthEnd->copy();

        $quantity = match ($contract->billing_type) {
            StorageBillingType::Free => 0,
            StorageBillingType::MonthlyFlat => 1,
            StorageBillingType::PerUnitDay => $this->unitDays($contract, $from, $to),
            StorageBillingType::PerOrder => $this->preparedOrders($contract, $from, $to),
        };
        $amount = $contract->billing_type === StorageBillingType::Free ? 0 : $quantity * $contract->price;

        return DB::transaction(function () use ($contract, $monthStart, $quantity, $amount) {
            $period = $monthStart->locale('fr')->translatedFormat('F Y');
            $detail = match ($contract->billing_type) {
                StorageBillingType::PerUnitDay => " : {$quantity} article(s)-jour(s)",
                StorageBillingType::PerOrder => " : {$quantity} commande(s) préparée(s)",
                default => '',
            };

            $entry = $amount > 0 ? MerchantLedgerEntry::create([
                'company_id' => $contract->company_id,
                'merchant_id' => $contract->merchant_id,
                'type' => LedgerEntryType::StorageFee,
                'amount' => -$amount,
                'description' => mb_substr("Stockage {$period}".($contract->hub ? " ({$contract->hub->name})" : '')." · {$contract->describe()}{$detail}", 0, 255),
            ]) : null;

            return StorageCharge::create([
                'company_id' => $contract->company_id,
                'merchant_id' => $contract->merchant_id,
                'storage_contract_id' => $contract->id,
                'period' => $monthStart->toDateString(),
                'quantity' => $quantity,
                'amount' => $amount,
                'ledger_entry_id' => $entry?->id,
            ]);
        });
    }

    /**
     * Somme, jour après jour, des articles en stock à l'entrepôt en fin de journée.
     */
    public function unitDays(StorageContract $contract, Carbon $from, Carbon $to): int
    {
        $locations = StockLocation::withoutGlobalScopes()
            ->where('merchant_id', $contract->merchant_id)
            ->when($contract->hub_id, fn ($q) => $q->where('hub_id', $contract->hub_id), fn ($q) => $q->whereNotNull('hub_id'))
            ->pluck('id');

        if ($locations->isEmpty()) {
            return 0;
        }

        $movements = fn () => StockMovement::withoutGlobalScopes()->whereIn('stock_location_id', $locations);

        $level = (int) $movements()->where('created_at', '<', $from)->sum('on_hand_change');
        $deltas = $movements()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to->copy()->addDay())
            ->get(['created_at', 'on_hand_change'])
            ->groupBy(fn (StockMovement $m) => $m->created_at->toDateString())
            ->map(fn ($rows) => (int) $rows->sum('on_hand_change'));

        $total = 0;
        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $level += $deltas[$day->toDateString()] ?? 0;
            $total += max(0, $level);
        }

        return $total;
    }

    private function preparedOrders(StorageContract $contract, Carbon $from, Carbon $to): int
    {
        return Order::withoutGlobalScopes()
            ->where('merchant_id', $contract->merchant_id)
            ->when($contract->hub_id, fn ($q) => $q->where('pickup_hub_id', $contract->hub_id), fn ($q) => $q->whereNotNull('pickup_hub_id'))
            ->where('prepared_at', '>=', $from)
            ->where('prepared_at', '<', $to->copy()->addDay())
            ->count();
    }
}
