<?php

namespace App\Services\Finance;

use App\Enums\LedgerEntryType;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\MerchantPayout;
use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * Détail de l'argent d'un client pour la caisse : ce qui reste à payer, course par course et rangé par date de
 * livraison (à la caisse ou encore chez un livreur), et les courses de chacun de ses derniers versements.
 */
class MerchantStatement
{
    public const PAYOUTS = 20;

    private const FEES = [LedgerEntryType::DeliveryFee, LedgerEntryType::ReturnFee, LedgerEntryType::ShippingFee, LedgerEntryType::OtherFee];

    public function __construct(private readonly MerchantPayouts $payouts) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Merchant $merchant): array
    {
        $unpaid = MerchantLedgerEntry::query()->where('merchant_id', $merchant->id)
            ->whereNull('payout_id')->where('type', '!=', LedgerEntryType::Payout->value)->get();
        $eligible = $this->payouts->eligibleEntries($merchant)->pluck('id')->flip();

        $payouts = MerchantPayout::query()->where('merchant_id', $merchant->id)
            ->with(['creator:id,name', 'payer:id,name'])->latest('id')->limit(self::PAYOUTS)->get();
        $payoutEntries = MerchantLedgerEntry::query()->whereIn('payout_id', $payouts->modelKeys())
            ->where('type', '!=', LedgerEntryType::Payout->value)->get()->groupBy('payout_id');

        $orders = $this->orders($unpaid->merge($payoutEntries->flatten())->pluck('order_id')->filter()->unique()->all());
        $summary = $this->payouts->summary($merchant);

        return [
            'available' => $summary['available'],
            'pending_cash' => $summary['pending_cash'],
            'unpaid_total' => $summary['unpaid_total'],
            'days' => $this->days($unpaid, $orders, $eligible),
            'others' => $this->others($unpaid, $eligible),
            'payouts' => $payouts->map(fn (MerchantPayout $p) => [
                'id' => $p->id,
                'reference' => $p->reference,
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
                'net_amount' => $p->net_amount,
                'method' => $p->method?->label(),
                'transaction_ref' => $p->transaction_ref,
                'created_at' => $p->created_at,
                'paid_at' => $p->paid_at,
                'created_by' => $p->creator?->name,
                'paid_by' => $p->payer?->name,
                'days' => collect($this->days($payoutEntries[$p->id] ?? collect(), $orders, null))
                    ->map(fn ($d) => ['day' => $d['day'], 'count' => $d['count'], 'net' => $d['net'], 'codes' => array_column($d['orders'], 'tracking_code')])->all(),
                'others' => collect($this->others($payoutEntries[$p->id] ?? collect(), null))->sum('amount'),
            ])->values()->all(),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Order>
     */
    private function orders(array $ids): Collection
    {
        return collect(array_chunk($ids, 1000))->flatMap(fn ($chunk) => Order::query()->whereIn('id', $chunk)
            ->with(['deliveryZone:id,name', 'cashCollection.courier.user:id,name'])
            ->selectRaw('orders.*, '.Order::DELIVERY_DAY_SQL.' AS delivery_day')->get())->keyBy('id');
    }

    /**
     * Écritures rangées par course puis par date de livraison (plus récente d'abord).
     *
     * @param  Collection<int, MerchantLedgerEntry>  $entries
     * @param  Collection<int, Order>  $orders
     * @param  Collection<int, int>|null  $eligible  écritures prêtes à payer (null : versement déjà fait)
     * @return list<array<string, mixed>>
     */
    private function days(Collection $entries, Collection $orders, ?Collection $eligible): array
    {
        return $entries->whereNotNull('order_id')->groupBy('order_id')
            ->map(function (Collection $rows, int $orderId) use ($orders, $eligible) {
                $order = $orders[$orderId] ?? null;
                $sum = fn (array $types) => (int) $rows->filter(fn ($e) => in_array($e->type, $types, true))->sum('amount');
                $collection = $order?->cashCollection;
                $ready = $eligible === null || $rows->every(fn ($e) => isset($eligible[$e->id]));

                return [
                    'order_id' => $orderId,
                    'day' => $order ? substr((string) $order->getAttribute('delivery_day'), 0, 10) : null,
                    'tracking_code' => $order?->tracking_code,
                    'recipient' => trim(($order?->recipient_name ?? '').($order?->deliveryZone ? ' · '.$order->deliveryZone->name : '')),
                    'collected' => $sum([LedgerEntryType::CodCredit]),
                    'fees' => $sum(self::FEES),
                    'other' => (int) $rows->sum('amount') - $sum([LedgerEntryType::CodCredit]) - $sum(self::FEES),
                    'net' => (int) $rows->sum('amount'),
                    // Où est l'argent : à la caisse (prêt), chez un livreur, ou en attente de vérification
                    'where' => $eligible === null ? 'paid' : ($ready ? 'desk' : ($order?->delivery_code_unverified_at ? 'unverified' : 'courier')),
                    'courier' => $ready ? null : $collection?->courier?->user?->name,
                ];
            })
            ->groupBy(fn ($o) => $o['day'] ?? '')
            ->map(fn (Collection $list, string $day) => [
                'day' => $day ?: null,
                'count' => $list->count(),
                'net' => $list->sum('net'),
                'available' => $list->where('where', 'desk')->sum('net'),
                'orders' => $list->sortBy('tracking_code')->values()->all(),
            ])
            ->sortByDesc(fn ($d) => $d['day'] ?? '')->values()->all();
    }

    /**
     * Lignes sans course (ajustements, stockage…).
     *
     * @return list<array<string, mixed>>
     */
    private function others(Collection $entries, ?Collection $eligible): array
    {
        return $entries->whereNull('order_id')->map(fn (MerchantLedgerEntry $e) => [
            'id' => $e->id,
            'label' => $e->description ?: $e->type->label(),
            'type_label' => $e->type->label(),
            'amount' => $e->amount,
            'created_at' => $e->created_at,
            'ready' => $eligible === null || isset($eligible[$e->id]),
        ])->values()->all();
    }
}
