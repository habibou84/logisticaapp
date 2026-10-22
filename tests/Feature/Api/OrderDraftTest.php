<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Aide à la saisie d'une course : message collé analysé, doublons du jour.
 */
class OrderDraftTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        config(['messaging.ai.api_key' => null]);
    }

    public function test_a_pasted_message_fills_the_order_fields(): void
    {
        Sanctum::actingAs($this->dispatcher);
        $response = $this->postJson('/api/v1/orders/parse-message', [
            'merchant_id' => $this->merchant->id,
            'text' => 'Livraison pour Awa Koné 0707123456 à Yopougon Siporex, 15000 F à encaisser',
        ])->assertOk();

        $response->assertJsonPath('data.recipient_phone', '+2250707123456')
            ->assertJsonPath('data.items_amount', 15000)
            ->assertJsonPath('data.zones.0.id', $this->yopougon->id);

        // L'e-commerçant aussi, depuis son application
        Sanctum::actingAs($this->merchantUser);
        $this->postJson('/api/v1/orders/parse-message', ['text' => 'Client 0505050505 Cocody'])
            ->assertOk()->assertJsonPath('data.zones.0.id', $this->cocody->id);

        // Les livreurs non
        Sanctum::actingAs($this->courierA->user);
        $this->postJson('/api/v1/orders/parse-message', ['text' => 'x'])->assertForbidden();
    }

    public function test_recent_orders_for_the_same_client_are_reported(): void
    {
        $order = $this->createOrder(['recipient_phone' => '0707070707']);

        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/orders/duplicates?'.http_build_query(['recipient_phone' => '07 07 07 07 07', 'merchant_id' => $this->merchant->id]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $order->id);

        // Autre client, ou course de plus de 24 heures : rien
        $this->getJson('/api/v1/orders/duplicates?'.http_build_query(['recipient_phone' => '0101010101', 'merchant_id' => $this->merchant->id]))
            ->assertJsonCount(0, 'data');
        $order->forceFill(['created_at' => now()->subDays(2)])->save();
        $this->getJson('/api/v1/orders/duplicates?'.http_build_query(['recipient_phone' => '0707070707', 'merchant_id' => $this->merchant->id]))
            ->assertJsonCount(0, 'data');
    }
}
