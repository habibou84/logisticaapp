<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\CourierRemittance;
use App\Models\IncidentReason;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Colis chez les livreurs : garde suivie à chaque étape, colis rendus lors du
 * point de caisse, point bloqué tant qu'une course est « En chemin », écran de
 * suivi et alerte au-delà du délai.
 */
class ParcelCustodyTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->cashier = $this->userWithRole(Role::Cashier);
    }

    private function move(Order $order, User $actor, string $status, array $extra = [])
    {
        Sanctum::actingAs($actor);

        return $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => $status, ...$extra]);
    }

    /**
     * Ramassée par A, puis en livraison chez B.
     */
    private function onTheRoad(array $data = []): Order
    {
        $order = $this->createOrder($data);
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();
        $this->move($order, $this->courierA->user, 'picked_up')->assertOk();
        $this->move($order, $this->courierB->user, 'out_for_delivery')->assertOk();

        return $order->fresh();
    }

    private function failDelivery(Order $order): Order
    {
        $reason = IncidentReason::where('applies_to', '!=', 'pickup')->where('requires_date', false)->where('triggers_return', false)->first();
        $this->move($order, $this->courierB->user, 'delivery_failed', ['incident_reason_id' => $reason->id])->assertOk();

        return $order->fresh();
    }

    public function test_custody_follows_the_parcel(): void
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->assertNull($order->fresh()->held_by_courier_id);

        $this->move($order, $this->courierA->user, 'picked_up')->assertOk();
        $this->assertSame($this->courierA->id, $order->fresh()->held_by_courier_id);
        $this->assertNotNull($order->fresh()->held_since);

        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        $this->assertSame($this->courierA->id, $order->fresh()->held_by_courier_id, 'Assigner ne déplace pas le colis');

        $this->move($order, $this->courierB->user, 'out_for_delivery')->assertOk();
        $order = $this->failDelivery($order);
        $this->assertSame($this->courierB->id, $order->held_by_courier_id, 'Après un échec, le colis reste chez le livreur');

        // Visible du personnel, pas du marchand
        Sanctum::actingAs($this->dispatcher);
        $this->getJson("/api/v1/orders/{$order->id}")->assertJsonPath('data.held_by.id', $this->courierB->id);
        Sanctum::actingAs($this->merchantUser);
        $this->getJson("/api/v1/orders/{$order->id}")->assertJsonMissingPath('data.held_by');

        // Le livreur le dépose lui-même au dépôt
        $this->move($order, $this->courierB->user, 'at_hub')->assertOk();
        $this->assertNull($order->fresh()->held_by_courier_id);

        // Livrée : plus chez personne
        $delivered = $this->onTheRoad();
        $this->move($delivered, $this->courierB->user, 'delivered')->assertOk();
        $this->assertNull($delivered->fresh()->held_by_courier_id);
    }

    public function test_cash_point_receives_parcels_and_is_blocked_while_on_the_road(): void
    {
        $delivered = $this->onTheRoad(['items_amount' => 10000]);
        $this->move($delivered, $this->courierB->user, 'delivered')->assertOk();
        $failed = $this->failDelivery($this->onTheRoad());
        $reportReason = IncidentReason::where('requires_date', true)->first();
        $rescheduled = $this->onTheRoad();
        $this->move($rescheduled, $this->courierB->user, 'delivery_failed', [
            'incident_reason_id' => $reportReason->id, 'rescheduled_to' => today()->addDay()->toDateString(),
        ])->assertOk();
        $road = $this->onTheRoad();

        Sanctum::actingAs($this->cashier);
        $this->getJson('/api/v1/finance/cash')->assertOk()
            ->assertJsonFragment(['courier_id' => $this->courierB->id, 'parcels_in_hand' => 3]);
        $parcels = $this->getJson("/api/v1/finance/couriers/{$this->courierB->id}/collections")->assertOk()->json('parcels');
        $this->assertSame([$failed->id, $rescheduled->id, $road->id], array_column($parcels, 'id'));
        $this->assertTrue($parcels[2]['on_the_road']);
        $this->assertSame(today()->addDay()->toDateString(), $parcels[1]['rescheduled_to']);

        // Course encore « En chemin » : pas de point
        $blocked = $this->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierB->id, 'amount_received' => 10000])
            ->assertJsonValidationErrors('courier_id');
        $this->assertStringContainsString($road->tracking_code, $blocked->json('message'));
        $this->assertSame(0, CourierRemittance::count());

        // Le livreur clôture la course, puis rend un colis et en garde un
        $this->failDelivery($road);
        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/v1/finance/remittances', [
            'courier_id' => $this->courierB->id, 'amount_received' => 10000,
            'returned_order_ids' => [$failed->id, $road->id],
        ])->assertCreated()
            ->assertJsonPath('data.parcels_returned', [$failed->tracking_code, $road->tracking_code])
            ->assertJsonPath('data.parcels_kept', [$rescheduled->tracking_code]);

        $failed->refresh();
        $this->assertNull($failed->held_by_courier_id);
        $this->assertSame('delivery_failed', $failed->status->value, 'Le dispatch décide de la suite');
        $this->assertSame('parcel_received', $failed->events()->latest('id')->first()->type->value);
        $this->assertSame($this->courierB->id, $rescheduled->fresh()->held_by_courier_id);
    }

    public function test_parcels_can_be_received_without_cash(): void
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->move($order, $this->courierA->user, 'picked_up')->assertOk();
        $road = $this->onTheRoad();

        // Le livreur ne peut pas se « rendre » un colis à lui-même
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/couriers/{$this->courierA->id}/parcels/receive", ['order_ids' => [$order->id]])->assertForbidden();

        Sanctum::actingAs($this->cashier);
        $this->postJson("/api/v1/couriers/{$this->courierA->id}/parcels/receive", ['order_ids' => [$road->id]])
            ->assertJsonValidationErrors('returned_order_ids');
        $this->postJson("/api/v1/couriers/{$this->courierB->id}/parcels/receive", ['order_ids' => [$road->id]])
            ->assertJsonValidationErrors('returned_order_ids');

        // Colis ramassé rendu par la caisse : il passe « Au dépôt »
        $this->postJson("/api/v1/couriers/{$this->courierA->id}/parcels/receive", ['order_ids' => [$order->id]])
            ->assertOk()
            ->assertJsonPath('data.received', [$order->tracking_code])
            ->assertJsonCount(0, 'parcels');
        $this->assertSame('at_hub', $order->fresh()->status->value);
        $this->assertNull($order->fresh()->held_by_courier_id);
    }

    public function test_overview_and_overdue_alerts(): void
    {
        Notification::fake();
        $old = $this->failDelivery($this->onTheRoad());
        $this->travel(25)->hours();
        $recent = $this->failDelivery($this->onTheRoad());

        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/parcels/held')->assertOk()
            ->assertJsonPath('data.0.courier_id', $this->courierB->id)
            ->assertJsonPath('data.0.count', 2)
            ->assertJsonPath('data.0.overdue', 1)
            ->assertJsonPath('data.0.parcels.0.id', $old->id)
            ->assertJsonPath('meta.alert_hours', 24);
        $this->getJson('/api/v1/parcels/held?overdue=1')->assertJsonPath('data.0.count', 1);
        $this->getJson('/api/v1/parcels/held/counts')->assertJsonPath('data', ['total' => 2, 'overdue' => 1]);

        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/parcels/held')->assertForbidden();

        $this->artisan('parcels:overdue')->assertSuccessful();
        Notification::assertSentTo($this->dispatcher, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'parcel_overdue'
            && $n->extra['custody'] === true && $n->extra['count'] === 1 && $n->order->is($old)
            && str_contains($n->title, $this->courierB->user->name));
        Notification::assertNotSentTo($this->courierB->user, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'parcel_overdue');

        // Une seule alerte par garde
        $this->artisan('parcels:overdue');
        $this->assertSame(1, $this->overdueAlerts());

        // Délai à 0 : plus d'alerte
        $this->company->update(['parcel_hold_alert_hours' => 0]);
        $this->travel(48)->hours();
        $this->artisan('parcels:overdue');
        $this->assertSame(1, $this->overdueAlerts());
        $this->assertNotNull($recent->fresh()->held_by_courier_id);
    }

    private function overdueAlerts(): int
    {
        return Notification::sent($this->dispatcher, OrderAlert::class)->filter(fn (OrderAlert $n) => $n->kind === 'parcel_overdue')->count();
    }
}
