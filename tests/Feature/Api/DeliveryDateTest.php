<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Une seule date compte : la date de livraison. Elle suit les reports et devient le jour où la course est livrée ;
 * la première date prévue reste à part, figée. Liste du jour, point du jour et statistiques comptent les mêmes courses.
 */
class DeliveryDateTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->buildWorld();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Course du 3, en route le 3, livrée par l'agence le 5 */
    private function deliveredLate(): Order
    {
        Carbon::setTestNow('2026-11-03 09:00:00');
        $order = $this->createOrder(['items_amount' => 10000, 'delivery_scheduled_date' => '2026-11-03']);
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierA->id])->assertOk();
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();

        Carbon::setTestNow('2026-11-05 15:00:00');
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();

        return $order->fresh();
    }

    public function test_the_first_date_is_kept_and_the_delivery_date_becomes_the_day_of_delivery(): void
    {
        $order = $this->deliveredLate();

        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame('2026-11-03', $order->first_delivery_date->toDateString());
        $this->assertSame('2026-11-05', $order->delivery_scheduled_date->toDateString());

        Sanctum::actingAs($this->admin);
        $detail = $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data.delivery');
        $this->assertSame(['2026-11-05', '2026-11-03'], [$detail['scheduled_date'], $detail['first_date']]);
    }

    public function test_the_card_and_the_list_count_the_same_courses(): void
    {
        $this->deliveredLate();
        // Une course du 5 livrée le 5, une autre du 5 pas encore livrée
        $this->createOrder(['delivery_scheduled_date' => '2026-11-05']);
        Sanctum::actingAs($this->admin);

        $day5 = $this->getJson('/api/v1/reports/day?day=2026-11-05')->assertOk()->json('data.counts');
        $list5 = $this->getJson('/api/v1/orders?day=2026-11-05&status[]=delivered')->assertOk()->json('meta.total');
        $this->assertSame([1, 2], [$day5['delivered'], $day5['planned']]);
        $this->assertSame($day5['delivered'], $list5);

        // Le 3 : plus rien de livré (la course a été livrée le 5)
        $day3 = $this->getJson('/api/v1/reports/day?day=2026-11-03')->assertOk()->json('data.counts');
        $this->assertSame([0, 0], [$day3['delivered'], $day3['planned']]);
    }

    public function test_late_deliveries_can_be_listed(): void
    {
        $late = $this->deliveredLate();
        Sanctum::actingAs($this->admin);

        $ids = collect($this->getJson('/api/v1/orders?delivered_late=1')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$late->id], $ids);
    }

    public function test_a_report_moves_the_delivery_date_but_not_the_first_one(): void
    {
        $order = $this->createOrder(['delivery_scheduled_date' => today()->toDateString()]);
        $order->forceFill(['delivery_scheduled_date' => today()->addDays(2)->toDateString()])->save();

        $this->assertSame(today()->toDateString(), $order->fresh()->first_delivery_date->toDateString());
        $this->assertSame(today()->addDays(2)->toDateString(), $order->fresh()->delivery_scheduled_date->toDateString());
    }
}
