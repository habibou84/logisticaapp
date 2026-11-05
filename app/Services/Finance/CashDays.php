<?php

namespace App\Services\Finance;

use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use App\Models\CashCollection;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * Point de la caisse par date de livraison (même date que la page Courses) : pour chaque jour, courses livrées,
 * argent encaissé chez les clients, déjà à la caisse, encore chez les livreurs, frais de livraison et à reverser.
 */
class CashDays
{
    /** Période la plus longue d'un point (jours) */
    public const MAX_DAYS = 92;

    private const FEES = [LedgerEntryType::DeliveryFee, LedgerEntryType::ReturnFee, LedgerEntryType::ShippingFee, LedgerEntryType::OtherFee];

    /**
     * @return array{days: list<array<string, int|string>>, totals: array<string, int>}
     */
    public function build(string $from, string $to): array
    {
        $orders = Order::query()->where('status', OrderStatus::Delivered)->forPeriod($from, $to, 'delivery')
            ->selectRaw('orders.id, '.Order::DELIVERY_DAY_SQL.' AS day')->get()
            ->mapWithKeys(fn (Order $o) => [$o->id => substr((string) $o->getAttribute('day'), 0, 10)]);

        $collections = collect();
        $fees = collect();
        foreach ($orders->keys()->chunk(1000) as $ids) {
            $collections = $collections->merge(CashCollection::query()->whereIn('order_id', $ids->all())
                ->get(['id', 'order_id', 'amount_collected', 'remittance_id', 'received_by_company', 'company_confirmed_at']));
            $fees = $fees->union(MerchantLedgerEntry::query()->whereIn('order_id', $ids->all())
                ->whereIn('type', array_map(fn (LedgerEntryType $t) => $t->value, self::FEES))
                ->groupBy('order_id')->selectRaw('order_id, SUM(amount) AS total')->pluck('total', 'order_id'));
        }
        $byOrder = $collections->groupBy('order_id');

        $rows = $orders->groupBy(fn ($day) => $day, preserveKeys: true)->map(function (Collection $dayOrders, string $day) use ($byOrder, $fees) {
            $collected = $withCouriers = $fee = 0;
            foreach ($dayOrders->keys() as $orderId) {
                foreach ($byOrder->get($orderId, collect()) as $c) {
                    $collected += (int) $c->amount_collected;
                    if (CashDays::inHands($c)) {
                        $withCouriers += (int) $c->amount_collected;
                    }
                }
                $fee += -(int) ($fees[$orderId] ?? 0);
            }

            return [
                'day' => $day,
                'delivered' => $dayOrders->count(),
                'collected' => $collected,
                'at_desk' => $collected - $withCouriers,
                'with_couriers' => $withCouriers,
                'fees' => $fee,
                'net_to_merchants' => $collected - $fee,
            ];
        })->sortByDesc('day')->values();

        $sum = fn (string $key) => (int) $rows->sum($key);

        return [
            'days' => $rows->all(),
            'totals' => [
                'delivered' => $sum('delivered'), 'collected' => $sum('collected'), 'at_desk' => $sum('at_desk'),
                'with_couriers' => $sum('with_couriers'), 'fees' => $sum('fees'), 'net_to_merchants' => $sum('net_to_merchants'),
            ],
        ];
    }

    /** Même règle que CashCollection::scopeInCourierHands, sur une ligne déjà chargée */
    public static function inHands(CashCollection $c): bool
    {
        return $c->remittance_id === null && (! $c->received_by_company || $c->company_confirmed_at === null);
    }

    /**
     * Argent encore chez chaque livreur, par date de livraison des courses.
     *
     * @return array<int, list<array{day: string, amount: int, count: int}>>
     */
    public function couriersByDay(): array
    {
        $collections = CashCollection::query()->inCourierHands()->get(['id', 'courier_id', 'order_id', 'amount_collected']);
        $days = collect();
        foreach ($collections->pluck('order_id')->unique()->chunk(1000) as $ids) {
            $days = $days->union(Order::query()->whereIn('id', $ids->all())->selectRaw('orders.id, '.Order::DELIVERY_DAY_SQL.' AS day')
                ->get()->mapWithKeys(fn (Order $o) => [$o->id => substr((string) $o->getAttribute('day'), 0, 10)]));
        }

        return $collections->groupBy('courier_id')->map(fn (Collection $rows) => $rows
            ->groupBy(fn (CashCollection $c) => $days[$c->order_id] ?? '')
            ->map(fn (Collection $group, string $day) => ['day' => $day, 'amount' => (int) $group->sum('amount_collected'), 'count' => $group->count()])
            ->sortBy('day')->values()->all())->all();
    }
}
