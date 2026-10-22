<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\IncidentReason;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\ReturnSlip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Bon de retour groupé : création par le dispatch, message au marchand,
 * remise signée par le livreur, colis non remis.
 */
class ReturnSlipTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    // Plus petit PNG valide (1 × 1 px)
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->merchant->update(['whatsapp_phone' => '0501020304']);
        Storage::fake('local');
    }

    private function move(Order $order, User $actor, string $status, array $extra = [])
    {
        Sanctum::actingAs($actor);

        return $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => $status, ...$extra]);
    }

    /**
     * Échec de livraison chez B, retour décidé, colis rendu au dépôt.
     */
    private function toReturn(bool $receivedAtHub = true): Order
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        $this->move($order, $this->courierA->user, 'picked_up')->assertOk();
        $this->move($order, $this->courierB->user, 'out_for_delivery')->assertOk();
        $reason = IncidentReason::where('applies_to', '!=', 'pickup')->where('requires_date', false)->where('triggers_return', false)->first();
        $this->move($order, $this->courierB->user, 'delivery_failed', ['incident_reason_id' => $reason->id])->assertOk();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/decision", ['decision' => 'return'])->assertOk();
        if ($receivedAtHub) {
            $this->postJson("/api/v1/couriers/{$this->courierB->id}/parcels/receive", ['order_ids' => [$order->id]])->assertOk();
        }

        return $order->fresh();
    }

    public function test_slip_is_created_signed_and_closes_the_returns(): void
    {
        $first = $this->toReturn();
        $second = $this->toReturn();
        $stillWithB = $this->toReturn(receivedAtHub: false);

        Sanctum::actingAs($this->dispatcher);
        $index = $this->getJson('/api/v1/return-slips')->assertOk();
        $index->assertJsonPath('candidates.0.merchant.id', $this->merchant->id)->assertJsonCount(3, 'candidates.0.orders');

        // Colis encore chez B : impossible de le confier à A
        $this->postJson('/api/v1/return-slips', [
            'merchant_id' => $this->merchant->id, 'courier_id' => $this->courierA->id, 'order_ids' => [$first->id, $stillWithB->id],
        ])->assertJsonValidationErrors('order_ids');

        $slipId = $this->postJson('/api/v1/return-slips', [
            'merchant_id' => $this->merchant->id, 'courier_id' => $this->courierA->id, 'order_ids' => [$first->id, $second->id],
        ])->assertCreated()
            ->assertJsonPath('data.orders_count', 2)
            ->assertJsonPath('data.status', 'open')
            ->json('data.id');

        $slip = ReturnSlip::find($slipId);
        $this->assertMatchesRegularExpression('/^BR-\d{6}-\d{5}$/', $slip->reference);
        $this->assertSame('return_assigned', $first->fresh()->status->value);
        $this->assertSame($this->courierA->id, $first->fresh()->return_courier_id);
        $created = OutboundMessage::where('template_name', 'bon_de_retour')->sole();
        $this->assertStringContainsString($slip->reference, $created->body);

        // Déjà sur un bon
        $this->postJson('/api/v1/return-slips', [
            'merchant_id' => $this->merchant->id, 'courier_id' => $this->courierA->id, 'order_ids' => [$first->id],
        ])->assertJsonValidationErrors('order_ids');

        // Le livreur voit son bon ; un autre livreur ne peut pas le remettre
        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/courier/return-slips')->assertOk()->assertJsonPath('data.0.reference', $slip->reference);
        $this->getJson("/api/v1/return-slips/{$slipId}")->assertOk()->assertJsonCount(2, 'data.orders');
        Sanctum::actingAs($this->courierB->user);
        $this->getJson("/api/v1/return-slips/{$slipId}")->assertForbidden();
        $this->postJson("/api/v1/return-slips/{$slipId}/hand-over", ['received_by_name' => 'X', 'signature' => self::PNG])
            ->assertJsonValidationErrors('status');

        // Sans signature ni photo : refusé
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/return-slips/{$slipId}/hand-over", ['received_by_name' => 'Mariam'])->assertJsonValidationErrors('signature');
        $this->postJson("/api/v1/return-slips/{$slipId}/hand-over", ['received_by_name' => 'Mariam', 'signature' => 'data:image/png;base64,AAAA'])
            ->assertJsonValidationErrors('signature');

        // Remise d'un seul colis, signée
        $this->postJson("/api/v1/return-slips/{$slipId}/hand-over", [
            'received_by_name' => 'Mariam Koné', 'signature' => self::PNG, 'order_ids' => [$first->id],
        ])->assertOk()
            ->assertJsonPath('data.status', 'handed')
            ->assertJsonPath('data.received_by_name', 'Mariam Koné')
            ->assertJsonPath('data.orders_count', 1)
            ->assertJsonPath('data.signature_url', "/api/v1/return-slips/{$slipId}/proof/signature");

        $this->assertSame('returned', $first->fresh()->status->value);
        $this->assertNull($first->fresh()->held_by_courier_id);
        $this->assertSame('return_assigned', $second->fresh()->status->value);
        $this->assertNull($second->fresh()->return_slip_id, 'Le colis non remis sort du bon');
        Storage::disk('local')->assertExists($slip->fresh()->signature_path);

        $handed = OutboundMessage::where('template_name', 'retour_remis')->sole();
        $this->assertStringContainsString('Mariam Koné', $handed->body);

        // Le marchand consulte son bon et la signature
        Sanctum::actingAs($this->merchantUser);
        $this->getJson("/api/v1/return-slips/{$slipId}")->assertOk()->assertJsonPath('data.orders.0.id', $first->id);
        $this->get("/api/v1/return-slips/{$slipId}/proof/signature")->assertOk();
        $this->getJson('/api/v1/return-slips')->assertForbidden();

        $other = $this->userWithRole(Role::MerchantOwner, ['merchant_id' => Merchant::factory()->create(['company_id' => $this->company->id])->id]);
        Sanctum::actingAs($other);
        $this->getJson("/api/v1/return-slips/{$slipId}")->assertForbidden();
    }

    public function test_photo_hand_over_and_cancellation(): void
    {
        $order = $this->toReturn();

        Sanctum::actingAs($this->dispatcher);
        $slipId = $this->postJson('/api/v1/return-slips', [
            'merchant_id' => $this->merchant->id, 'courier_id' => $this->courierB->id, 'order_ids' => [$order->id],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/return-slips/{$slipId}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertNull($order->fresh()->return_slip_id);
        $this->assertSame('return_assigned', $order->fresh()->status->value, 'La mission de retour reste');

        $slipId = $this->postJson('/api/v1/return-slips', [
            'merchant_id' => $this->merchant->id, 'courier_id' => $this->courierB->id, 'order_ids' => [$order->id],
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->courierB->user);
        $this->post("/api/v1/return-slips/{$slipId}/hand-over", [
            'received_by_name' => 'Gérant', 'photo' => UploadedFile::fake()->image('remise.jpg'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.photo_url', "/api/v1/return-slips/{$slipId}/proof/photo");

        $this->assertSame('returned', $order->fresh()->status->value);
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/return-slips/{$slipId}/cancel")->assertJsonValidationErrors('status');
    }
}
