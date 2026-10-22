<?php

namespace Tests\Feature\Api;

use App\Enums\AssignmentType;
use App\Notifications\OrderAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Retirer le livreur d'une mission : la course attend un autre livreur.
 */
class UnassignCourierTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_a_pickup_courier_can_be_removed_even_on_the_way(): void
    {
        Notification::fake();
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
        Sanctum::actingAs($this->courierA->user);
        $this->postJson('/api/v1/courier/assignments/'.$order->activeAssignment(AssignmentType::Pickup)->id.'/accept')->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'pickup_in_progress'])->assertOk();

        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/unassign", ['type' => 'pickup', 'reason' => 'Erreur de livreur'])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.pickup_courier', null);

        $order->refresh();
        $this->assertNull($order->activeAssignment(AssignmentType::Pickup));
        $this->assertTrue($order->events()->where('type', 'unassigned')->where('note', 'Erreur de livreur')->exists());
        Notification::assertSentTo($this->courierA->user, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'mission_removed');

        // Plus dans ses missions, et assignable à un autre livreur
        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/courier/missions')->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierB->id])
            ->assertOk()->assertJsonPath('data.status', 'pickup_assigned');
    }

    public function test_a_delivery_courier_is_removed_until_he_leaves_with_the_parcel(): void
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();

        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk()
            ->assertJsonPath('data.status', 'delivery_assigned');
        $this->postJson("/api/v1/orders/{$order->id}/unassign", ['type' => 'delivery'])->assertOk()
            ->assertJsonPath('data.status', 'picked_up')
            ->assertJsonPath('data.delivery_courier', null);

        // En route avec le colis : refusé
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/unassign", ['type' => 'delivery'])->assertUnprocessable();

        // Rien à retirer, et réservé au dispatch
        $this->postJson("/api/v1/orders/{$order->id}/unassign", ['type' => 'return'])->assertUnprocessable();
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/orders/{$order->id}/unassign", ['type' => 'delivery'])->assertForbidden();
    }

    public function test_couriers_are_removed_from_several_orders_at_once(): void
    {
        $a = $this->createOrder();
        $b = $this->createOrder();
        $c = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson('/api/v1/orders/bulk-assign', ['order_ids' => [$a->id, $b->id], 'type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();

        // Sans type : la mission de l'étape en cours ; une course sans livreur est signalée
        $this->postJson('/api/v1/orders/bulk-unassign', ['order_ids' => [$a->id, $b->id, $c->id], 'reason' => 'Livreur malade'])
            ->assertOk()
            ->assertJsonPath('data.unassigned', [$a->id, $b->id])
            ->assertJsonCount(1, 'data.errors');

        $this->assertSame('confirmed', $a->fresh()->status->value);
        $this->assertNull($b->fresh()->pickup_courier_id);

        Sanctum::actingAs($this->courierA->user);
        $this->postJson('/api/v1/orders/bulk-unassign', ['order_ids' => [$a->id]])->assertForbidden();
    }
}
