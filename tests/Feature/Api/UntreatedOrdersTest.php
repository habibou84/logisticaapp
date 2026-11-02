<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\BillingPlan;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Page Courses : chaque onglet ne montre que les courses du jour choisi ; les courses dont le jour de livraison est
 * dépassé, pas terminées et plus dans la règle de leur activité sont dans « Courses non traitées », regroupées par date,
 * par livreur ou par client. Distribution : seulement après l'échéance du lot ; MAD : jamais.
 */
class UntreatedOrdersTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->buildWorld();
    }

    private function orderOn(string $day, OrderStatus $status, array $attributes = []): Order
    {
        $order = $this->createOrder();
        $order->forceFill(['delivery_scheduled_date' => $day, 'status' => $status, ...$attributes])->save();

        return $order;
    }

    private function ids(string $url): array
    {
        return collect($this->getJson($url)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    public function test_every_tab_shows_only_the_chosen_day(): void
    {
        $today = today()->toDateString();
        $yesterday = today()->subDay()->toDateString();
        $pendingToday = $this->orderOn($today, OrderStatus::Pending);
        $this->orderOn($yesterday, OrderStatus::Pending);
        $failedToday = $this->orderOn($today, OrderStatus::DeliveryFailed);
        $this->orderOn($yesterday, OrderStatus::DeliveryFailed);
        $returnToday = $this->orderOn($today, OrderStatus::PickedUp, ['return_requested' => true]);
        $this->orderOn($yesterday, OrderStatus::PickedUp, ['return_requested' => true]);

        Sanctum::actingAs($this->dispatcher);
        $this->assertSame([$pendingToday->id], $this->ids("/api/v1/orders?queue=to_confirm&day={$today}"));
        $this->assertSame([$failedToday->id], $this->ids("/api/v1/orders?queue=failed&day={$today}"));
        $this->assertSame([$returnToday->id], $this->ids("/api/v1/orders?queue=to_return&day={$today}"));

        $day = $this->getJson("/api/v1/orders/counts?day={$today}")->assertOk()->json('data.day');
        $this->assertSame([1, 1, 1], [$day['to_confirm'], $day['failed'], $day['to_return']]);
        $this->assertCount(2, $this->ids('/api/v1/orders?queue=to_confirm'));
    }

    public function test_untreated_orders_are_grouped_by_date_courier_or_client(): void
    {
        $twoDaysAgo = today()->subDays(2)->toDateString();
        $yesterday = today()->subDay()->toDateString();
        $withCourier = $this->orderOn($twoDaysAgo, OrderStatus::DeliveryAssigned, ['delivery_courier_id' => $this->courierB->id, 'pickup_courier_id' => $this->courierA->id]);
        $alone = $this->orderOn($yesterday, OrderStatus::Confirmed);
        $returning = $this->orderOn($yesterday, OrderStatus::PickedUp, ['return_requested' => true]);
        $this->orderOn($yesterday, OrderStatus::Delivered);
        $this->orderOn(today()->toDateString(), OrderStatus::Confirmed);
        // Distribution d'un lot pas encore échu, MAD : pas en retard
        $lot = BillingPlan::create([
            'company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'formula' => 'distribution_lot', 'activity' => 'distribution',
            'delivery_delay' => '48h', 'starts_on' => today()->subWeek()->toDateString(), 'lot_label' => 'Relevés', 'lot_quantity' => 500,
            'lot_price' => 100000, 'lot_due_on' => today()->toDateString(),
        ]);
        $this->orderOn($twoDaysAgo, OrderStatus::PickedUp, ['activity' => 'distribution', 'billing_plan_id' => $lot->id]);
        $this->orderOn($twoDaysAgo, OrderStatus::PickedUp, ['activity' => 'mad']);

        Sanctum::actingAs($this->dispatcher);
        $this->assertSame(collect([$withCourier->id, $alone->id, $returning->id])->sort()->values()->all(), $this->ids('/api/v1/orders?queue=untreated'));
        $this->assertSame(3, $this->getJson('/api/v1/orders/counts')->json('data.exceptions.untreated'));

        $byDate = $this->getJson('/api/v1/orders/untreated-groups?by=date')->assertOk()->json('data');
        $this->assertSame(3, $byDate['total']);
        $this->assertSame([[$twoDaysAgo, 1], [$yesterday, 2]], array_map(fn ($g) => [$g['key'], $g['count']], $byDate['groups']));
        $this->assertSame(collect([$alone->id, $returning->id])->sort()->values()->all(), $this->ids("/api/v1/orders?queue=untreated&from={$yesterday}&to={$yesterday}&date_basis=delivery"));

        $byCourier = collect($this->getJson('/api/v1/orders/untreated-groups?by=courier')->json('data.groups'))->keyBy('key');
        $this->assertSame([1, $this->courierB->user->name], [$byCourier[(string) $this->courierB->id]['count'], $byCourier[(string) $this->courierB->id]['label']]);
        $this->assertSame([$withCourier->id], $this->ids("/api/v1/orders?queue=untreated&lead_courier_id={$this->courierB->id}"));
        $this->assertSame(2, $byCourier['none']['count']);
        $this->assertCount(2, $this->ids('/api/v1/orders?queue=untreated&without_courier=1'));

        $byMerchant = $this->getJson('/api/v1/orders/untreated-groups?by=merchant')->json('data.groups');
        $this->assertSame([(string) $this->merchant->id, 3, $this->merchant->business_name], [$byMerchant[0]['key'], $byMerchant[0]['count'], $byMerchant[0]['label']]);
        $this->assertSame(0, $this->getJson('/api/v1/orders/untreated-groups?activity=distribution')->json('data.total'));

        // Réservé au dispatch
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/orders/untreated-groups')->assertForbidden();
    }

    public function test_untreated_orders_get_a_new_date_or_are_cancelled_together(): void
    {
        $yesterday = today()->subDay()->toDateString();
        $first = $this->orderOn($yesterday, OrderStatus::Confirmed);
        $second = $this->orderOn($yesterday, OrderStatus::PickedUp);
        $gone = $this->orderOn($yesterday, OrderStatus::OutForDelivery);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/orders/bulk-reschedule', ['order_ids' => [$first->id], 'date' => $yesterday])->assertJsonValidationErrors('date');
        $result = $this->postJson('/api/v1/orders/bulk-reschedule', ['order_ids' => [$first->id, $second->id, $gone->id], 'date' => today()->toDateString()])
            ->assertOk()->json('data');
        $this->assertSame([$first->id, $second->id], $result['rescheduled']);
        $this->assertArrayHasKey($gone->id, $result['errors']);
        $this->assertSame([$gone->id], $this->ids('/api/v1/orders?queue=untreated'));
        $this->assertContains($first->id, $this->ids('/api/v1/orders?day='.today()->toDateString()));

        $cancel = $this->orderOn($yesterday, OrderStatus::Confirmed);
        $this->postJson('/api/v1/orders/bulk-cancel', ['order_ids' => [$cancel->id], 'reason' => 'Client injoignable'])
            ->assertOk()->assertJsonPath('data.cancelled', [$cancel->id]);
        $this->assertSame(['cancelled', 'Client injoignable'], [$cancel->fresh()->status->value, $cancel->fresh()->cancel_reason]);

        Sanctum::actingAs($this->merchantUser);
        $this->postJson('/api/v1/orders/bulk-cancel', ['order_ids' => [$first->id]])->assertForbidden();
    }
}
