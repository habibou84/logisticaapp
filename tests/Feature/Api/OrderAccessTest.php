<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

class OrderAccessTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function otherMerchantOrder(): Order
    {
        $other = Merchant::factory()->create(['company_id' => $this->company->id, 'pickup_zone_id' => $this->cocody->id]);

        return $this->createOrder(actor: $this->admin, merchant: $other);
    }

    public function test_merchant_only_sees_its_own_orders(): void
    {
        $mine = $this->createOrder();
        $theirs = $this->otherMerchantOrder();
        Sanctum::actingAs($this->merchantUser);

        $ids = collect($this->getJson('/api/v1/orders')->assertOk()->json('data'))->pluck('id');
        $this->assertEquals([$mine->id], $ids->all());

        $this->getJson("/api/v1/orders/{$theirs->id}")->assertForbidden();
        $this->patchJson("/api/v1/orders/{$theirs->id}", ['delivery_address' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/orders/{$theirs->id}/notes", ['note' => 'x'])->assertForbidden();
    }

    public function test_merchant_cannot_dispatch_or_create_for_another_merchant(): void
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->merchantUser);

        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertForbidden();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'confirmed'])->assertForbidden();
        $this->postJson('/api/v1/orders', [
            'merchant_id' => Merchant::factory()->create(['company_id' => $this->company->id])->id,
            'recipient_phone' => '0707070707',
            'delivery_zone_id' => $this->yopougon->id,
        ])->assertJsonValidationErrors('merchant_id');
    }

    public function test_staff_must_choose_the_merchant(): void
    {
        Sanctum::actingAs($this->dispatcher);

        $this->postJson('/api/v1/orders', ['recipient_phone' => '0707070707', 'delivery_zone_id' => $this->yopougon->id])
            ->assertJsonValidationErrors('merchant_id');

        // Qui paie la livraison : à choisir, jamais par défaut
        $this->postJson('/api/v1/orders', [
            'merchant_id' => $this->merchant->id,
            'recipient_phone' => '0707070707',
            'delivery_zone_id' => $this->yopougon->id,
        ])->assertJsonValidationErrors(['fee_payer' => 'Indiquez qui paie la livraison : le marchand ou le client.']);

        $this->postJson('/api/v1/orders', [
            'merchant_id' => $this->merchant->id,
            'recipient_phone' => '0707070707',
            'delivery_zone_id' => $this->yopougon->id,
            'fee_payer' => 'merchant',
        ])->assertCreated()->assertJsonPath('data.source', 'admin');
    }

    public function test_courier_only_acts_on_missions_assigned_to_them(): void
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);

        Sanctum::actingAs($this->courierB->user);
        $this->getJson("/api/v1/orders/{$order->id}")->assertForbidden();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertForbidden();

        Sanctum::actingAs($this->courierA->user);
        $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonMissingPath('data.delivery_code');
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered'])->assertUnprocessable();
        $this->getJson('/api/v1/orders')->assertForbidden(); // liste réservée : le livreur passe par ses missions
    }

    public function test_courier_cannot_cancel_or_confirm(): void
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);

        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'cancelled'])->assertForbidden();
    }

    public function test_courier_space_is_reserved_to_couriers(): void
    {
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/courier/missions')->assertForbidden();
    }

    public function test_courier_updates_availability_and_position(): void
    {
        Sanctum::actingAs($this->courierA->user);

        $this->patchJson('/api/v1/courier/status', ['is_available' => false, 'lat' => 5.35, 'lng' => -4.0])
            ->assertOk()
            ->assertJsonPath('data.is_available', false)
            ->assertJsonPath('data.current_lat', 5.35);
    }

    public function test_courier_of_another_company_cannot_be_assigned(): void
    {
        $order = $this->createOrder();
        $foreignCourier = User::factory()->withRole(Role::Courier)->create()->courier;
        Sanctum::actingAs($this->dispatcher);

        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $foreignCourier->id])
            ->assertJsonValidationErrors('courier_id');
    }

    public function test_suspended_courier_cannot_be_assigned(): void
    {
        $order = $this->createOrder();
        $this->courierA->user->update(['status' => 'suspended']);
        Sanctum::actingAs($this->dispatcher);

        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])
            ->assertUnprocessable();
    }

    public function test_internal_notes_are_hidden_from_the_merchant(): void
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/notes", ['note' => 'Client difficile', 'visible_to_merchant' => false])->assertCreated();
        $this->postJson("/api/v1/orders/{$order->id}/notes", ['note' => 'Ramassage prévu à 14h'])->assertCreated();

        Sanctum::actingAs($this->merchantUser);
        $notes = collect($this->getJson("/api/v1/orders/{$order->id}")->json('data.events'))->pluck('note')->filter()->values();

        $this->assertEquals(['Ramassage prévu à 14h'], $notes->all());
    }

    public function test_search_by_tracking_code_and_phone(): void
    {
        $order = $this->createOrder(['recipient_phone' => '0505050505']);
        $this->createOrder();
        Sanctum::actingAs($this->dispatcher);

        $this->getJson('/api/v1/orders?search='.strtolower($order->tracking_code))->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/orders?search=05 05 05 05 05')->assertJsonCount(1, 'data');
    }
}
