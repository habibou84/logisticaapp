<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Caisse par date de livraison : point par jour, argent des livreurs par jour, versements, e-commerçants et
 * grand livre limités aux courses d'une période (même date que la page Courses).
 */
class CashByDayTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->buildWorld();
        $this->cashier = $this->userWithRole(Role::Cashier);
        $this->payPlan(['pickup' => 300, 'delivery' => 500], $this->courierA);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Course livrée par le livreur A le jour donné */
    private function deliveredOn(string $day, int $amount): Order
    {
        Carbon::setTestNow("$day 09:00:00");
        $order = $this->createOrder(['items_amount' => $amount, 'delivery_scheduled_date' => $day]);
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierA->id])->assertOk();
        Sanctum::actingAs($this->courierA->user);
        foreach (['picked_up', 'out_for_delivery', 'delivered'] as $status) {
            $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => $status])->assertOk();
        }

        return $order->fresh();
    }

    /** Deux courses le 3, une le 4 ; l'argent de la première course du 3 est versé à la caisse */
    private function world(): array
    {
        $a = $this->deliveredOn('2026-11-03', 10000);
        $b = $this->deliveredOn('2026-11-03', 5000);
        $c = $this->deliveredOn('2026-11-04', 20000);
        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/v1/finance/remittances', [
            'courier_id' => $this->courierA->id, 'amount_received' => 10000, 'collection_ids' => [$a->cashCollection->id],
        ])->assertCreated();

        return [$a, $b, $c];
    }

    public function test_the_cash_point_is_grouped_by_delivery_day(): void
    {
        $this->world();

        $data = $this->getJson('/api/v1/finance/cash/days?from=2026-11-01&to=2026-11-04')->assertOk()->json('data');
        $days = collect($data['days'])->keyBy('day');
        $this->assertSame(['2026-11-04', '2026-11-03'], collect($data['days'])->pluck('day')->all());
        $this->assertSame([2, 15000, 10000, 5000], [$days['2026-11-03']['delivered'], $days['2026-11-03']['collected'], $days['2026-11-03']['at_desk'], $days['2026-11-03']['with_couriers']]);
        $this->assertSame(3000, $days['2026-11-03']['fees']);
        $this->assertSame(12000, $days['2026-11-03']['net_to_merchants']);
        $this->assertSame([3, 35000, 25000], [$data['totals']['delivered'], $data['totals']['collected'], $data['totals']['with_couriers']]);

        // Un seul jour
        $this->getJson('/api/v1/finance/cash/days?from=2026-11-04&to=2026-11-04')->assertOk()
            ->assertJsonPath('data.totals.collected', 20000);
        // Période trop longue
        $this->getJson('/api/v1/finance/cash/days?from=2026-01-01&to=2026-11-04')->assertUnprocessable();
    }

    public function test_the_money_with_each_courier_is_split_by_delivery_day(): void
    {
        $this->world();

        $row = collect($this->getJson('/api/v1/finance/cash')->assertOk()->json('data'))->firstWhere('courier_id', $this->courierA->id);
        $this->assertSame([['day' => '2026-11-03', 'amount' => 5000, 'count' => 1], ['day' => '2026-11-04', 'amount' => 20000, 'count' => 1]], $row['days']);

        $days = collect($this->getJson("/api/v1/finance/couriers/{$this->courierA->id}/collections")->assertOk()->json('data'))->pluck('delivery_day')->sort()->values()->all();
        $this->assertSame(['2026-11-03', '2026-11-04'], $days);
    }

    public function test_remittances_show_and_filter_by_delivery_day(): void
    {
        $this->world();
        $this->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierA->id, 'amount_received' => 25000])->assertCreated();

        // Reçus le 4 : les deux versements ; par date de livraison du 4 : seulement celui qui contient la course du 4
        $this->assertCount(2, $this->getJson('/api/v1/finance/remittances?from=2026-11-04&to=2026-11-04')->assertOk()->json('data'));
        $byDelivery = $this->getJson('/api/v1/finance/remittances?from=2026-11-04&to=2026-11-04&date_basis=delivery')->assertOk()->json('data');
        $this->assertCount(1, $byDelivery);
        $this->assertSame([['day' => '2026-11-03', 'amount' => 5000, 'count' => 1], ['day' => '2026-11-04', 'amount' => 20000, 'count' => 1]], $byDelivery[0]['days']);
    }

    public function test_merchants_ledger_and_payout_can_be_limited_to_a_period(): void
    {
        [, , $c] = $this->world();
        // Tout l'argent arrive à la caisse
        $this->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierA->id, 'amount_received' => 25000])->assertCreated();

        $all = collect($this->getJson('/api/v1/finance/merchants')->assertOk()->json('data'))->firstWhere('merchant_id', $this->merchant->id);
        $day3 = collect($this->getJson('/api/v1/finance/merchants?from=2026-11-03&to=2026-11-03')->assertOk()->json('data'))->firstWhere('merchant_id', $this->merchant->id);
        $this->assertSame(30500, $all['available']);
        $this->assertSame(12000, $day3['available']);

        $ledger = $this->getJson("/api/v1/finance/merchants/{$this->merchant->id}/ledger?from=2026-11-03&to=2026-11-03")->assertOk()->json();
        $this->assertSame(['2026-11-03'], collect($ledger['data'])->pluck('delivery_day')->unique()->values()->all());

        // Payer seulement les courses du 3 : celle du 4 reste à payer
        $this->postJson('/api/v1/finance/payouts', ['merchant_id' => $this->merchant->id, 'from' => '2026-11-03', 'to' => '2026-11-03'])
            ->assertCreated()->assertJsonPath('data.net_amount', 12000);
        $this->assertSame(0, MerchantLedgerEntry::where('order_id', $c->id)->whereNotNull('payout_id')->count());
        $left = collect($this->getJson('/api/v1/finance/merchants')->json('data'))->firstWhere('merchant_id', $this->merchant->id);
        $this->assertSame(18500, $left['available']);
    }

    public function test_merchant_detail_lists_orders_by_day_and_the_orders_of_each_payout(): void
    {
        [$a, $b, $c] = $this->world();

        $row = collect($this->getJson('/api/v1/finance/merchants?from=2026-11-03&to=2026-11-03')->assertOk()->json('data'))->firstWhere('merchant_id', $this->merchant->id);
        $this->assertSame([2, 12000, 30500], [$row['period_count'], $row['unpaid_total'], $row['all']['unpaid_total']]);

        $detail = $this->getJson("/api/v1/finance/merchants/{$this->merchant->id}/statement")->assertOk()->json('data');
        $this->assertSame(['2026-11-04', '2026-11-03'], array_column($detail['days'], 'day'));
        $this->assertSame([1, 18500, 0], [$detail['days'][0]['count'], $detail['days'][0]['net'], $detail['days'][0]['available']]);
        $this->assertSame(['courier', 'Koffi Ramasseur'], [$detail['days'][0]['orders'][0]['where'], $detail['days'][0]['orders'][0]['courier']]);
        $day3 = collect($detail['days'][1]['orders'])->keyBy('tracking_code');
        $this->assertSame(['desk', 10000, -1500, 8500], [$day3[$a->tracking_code]['where'], $day3[$a->tracking_code]['collected'], $day3[$a->tracking_code]['fees'], $day3[$a->tracking_code]['net']]);
        $this->assertSame('courier', $day3[$b->tracking_code]['where']);

        // Tout arrive, on paie le 3 : le versement liste ses courses par date, le 4 reste à payer
        $this->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierA->id, 'amount_received' => 25000])->assertCreated();
        $this->postJson('/api/v1/finance/payouts', ['merchant_id' => $this->merchant->id, 'from' => '2026-11-03', 'to' => '2026-11-03'])->assertCreated();
        $detail = $this->getJson("/api/v1/finance/merchants/{$this->merchant->id}/statement")->json('data');
        $this->assertSame(['2026-11-04'], array_column($detail['days'], 'day'));
        $this->assertSame('desk', $detail['days'][0]['orders'][0]['where']);
        $this->assertSame([12000, '2026-11-03'], [$detail['payouts'][0]['net_amount'], $detail['payouts'][0]['days'][0]['day']]);
        $this->assertEqualsCanonicalizing([$a->tracking_code, $b->tracking_code], $detail['payouts'][0]['days'][0]['codes']);

        // Réservé à l'équipe de la caisse
        Sanctum::actingAs($this->merchantUser);
        $this->getJson("/api/v1/finance/merchants/{$this->merchant->id}/statement")->assertForbidden();
    }
}
