<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\IncidentReason;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    private function move(Order $order, string $status, array $extra = [])
    {
        return $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => $status, ...$extra]);
    }

    private function assign(Order $order, string $type, $courier)
    {
        return $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => $type, 'courier_id' => $courier->id]);
    }

    private function reason(string $code): int
    {
        return IncidentReason::where('code', $code)->value('id');
    }

    public function test_merchant_creates_an_order_with_a_server_side_frozen_price(): void
    {
        $response = $this->as($this->merchantUser)->postJson('/api/v1/orders', [
            'recipient_name' => 'Jean Kouadio',
            'recipient_phone' => '07 07 07 07 07',
            'delivery_zone_id' => $this->yopougon->id,
            'delivery_address' => 'Siporex',
            'items_amount' => 15000,
            'fee_payer' => 'recipient',
            'delivery_fee' => 1, // ignoré : le prix est calculé par le serveur
        ])->assertCreated();

        $response
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.pickup.zone_id', $this->cocody->id) // adresse du marchand par défaut
            ->assertJsonPath('data.recipient.phone', '+2250707070707')
            ->assertJsonPath('data.amounts.delivery_fee', 1500)
            ->assertJsonPath('data.amounts.cod_amount', 16500) // articles + frais payés par le destinataire
            ->assertJsonPath('data.events.0.type', 'created')
            ->assertJsonPath('data.events.0.actor_name', $this->merchantUser->name);

        $this->assertMatchesRegularExpression('/^LV-[2-9A-Z]{4}-[2-9A-Z]{4}$/', $response->json('data.tracking_code'));
        $this->assertMatchesRegularExpression('/^\d{4}$/', $response->json('data.delivery_code'));

        // Changer la grille ensuite ne modifie pas la course
        $this->grid->rules()->update(['price' => 9999]);
        $this->getJson('/api/v1/orders/'.$response->json('data.id'))->assertJsonPath('data.amounts.delivery_fee', 1500);
    }

    public function test_full_lifecycle_with_different_pickup_and_delivery_couriers(): void
    {
        $order = $this->createOrder();

        $this->as($this->dispatcher)->move($order, 'confirmed')->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assign($order, 'pickup', $this->courierA)->assertOk()
            ->assertJsonPath('data.status', 'pickup_assigned')
            ->assertJsonPath('data.pickup_courier.name', 'Koffi Ramasseur');

        $this->as($this->courierA->user);
        $this->postJson('/api/v1/courier/assignments/'.$order->assignments()->first()->id.'/accept')->assertOk();
        $this->move($order, 'pickup_in_progress')->assertOk();
        $this->move($order, 'picked_up', ['lat' => 5.36, 'lng' => -3.98])->assertOk();
        $this->move($order, 'at_hub')->assertOk();

        $this->as($this->dispatcher)->assign($order, 'delivery', $this->courierB)->assertOk()
            ->assertJsonPath('data.status', 'delivery_assigned');

        $this->as($this->courierB->user);
        $this->move($order, 'out_for_delivery')->assertOk();
        $this->move($order, 'delivered', ['collected_amount' => 10000])->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.amounts.collected_amount', 10000);

        // Chaque étape et chaque intervenant sont tracés
        $events = $order->events()->orderBy('id')->get();
        $this->assertSame(
            ['created', 'status_changed', 'assigned', 'assignment_accepted', 'status_changed', 'status_changed', 'status_changed', 'assigned', 'status_changed', 'status_changed'],
            $events->pluck('type')->map->value->all(),
        );
        $this->assertSame(
            [$this->merchantUser->id, $this->dispatcher->id, $this->dispatcher->id, $this->courierA->user_id, $this->courierA->user_id,
                $this->courierA->user_id, $this->courierA->user_id, $this->dispatcher->id, $this->courierB->user_id, $this->courierB->user_id],
            $events->pluck('actor_id')->all(),
        );
        $this->assertSame(5.36, $events[5]->lat);

        $order->refresh();
        $this->assertSame($this->courierA->id, $order->pickup_courier_id);
        $this->assertSame($this->courierB->id, $order->delivery_courier_id);
        $this->assertNotNull($order->picked_up_at);
        $this->assertNotNull($order->delivered_at);
        $this->assertSame(['completed', 'completed'], $order->assignments()->orderBy('id')->pluck('status')->map->value->all());
        $this->assertSame(1, $order->recipient->deliveries_count);
    }

    public function test_same_courier_can_pick_up_and_deliver_directly(): void
    {
        $order = $this->createOrder();
        $this->as($this->dispatcher);
        $this->assign($order, 'pickup', $this->courierA)->assertOk();
        $this->assign($order, 'delivery', $this->courierA)->assertOk()->assertJsonPath('data.status', 'pickup_assigned');

        $this->as($this->courierA->user);
        $this->move($order, 'picked_up')->assertOk();
        $this->move($order, 'out_for_delivery')->assertOk();
        $this->move($order, 'delivered')->assertOk()->assertJsonPath('data.amounts.collected_amount', 10000);
    }

    public function test_assigning_a_pending_order_confirms_it(): void
    {
        $order = $this->createOrder();

        $this->as($this->dispatcher)->assign($order, 'pickup', $this->courierA)->assertOk();

        $this->assertNotNull($order->fresh()->confirmed_at);
    }

    public function test_auto_confirmation_setting(): void
    {
        $this->company->update(['auto_confirm_orders' => true]);

        $order = $this->createOrder();

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame('system', $order->events()->latest('id')->first()->actor_type);
    }

    public function test_out_for_delivery_requires_a_delivery_courier(): void
    {
        $order = $this->createOrder();
        $this->as($this->dispatcher)->assign($order, 'pickup', $this->courierA);
        $this->as($this->courierA->user)->move($order, 'picked_up')->assertOk();

        $this->as($this->dispatcher)->move($order, 'out_for_delivery')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Assignez d\'abord un livreur pour la livraison.');
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $order = $this->createOrder();

        $this->as($this->dispatcher)->move($order, 'delivered')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Transition impossible : « En attente de validation » → « Livré ».');
    }

    public function test_courier_refusal_puts_the_order_back_for_dispatch(): void
    {
        $order = $this->createOrder();
        $this->as($this->dispatcher)->assign($order, 'pickup', $this->courierA);
        $this->assign($order, 'pickup', $this->courierB); // réassignation
        $assignment = $order->assignments()->latest('id')->first();

        $this->as($this->courierB->user)
            ->postJson("/api/v1/courier/assignments/{$assignment->id}/refuse", ['reason' => 'Moto en panne'])
            ->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertNull($order->pickup_courier_id);
        $this->assertSame(['cancelled', 'refused'], $order->assignments()->orderBy('id')->pluck('status')->map->value->all());

        // Une mission déjà traitée ne peut pas être acceptée
        $this->postJson("/api/v1/courier/assignments/{$assignment->id}/accept")->assertUnprocessable();
    }

    public function test_courier_cannot_respond_to_someone_elses_mission(): void
    {
        $order = $this->createOrder();
        $this->as($this->dispatcher)->assign($order, 'pickup', $this->courierA);

        $this->as($this->courierB->user)
            ->postJson('/api/v1/courier/assignments/'.$order->assignments()->first()->id.'/accept')
            ->assertForbidden();
    }

    public function test_courier_missions_list(): void
    {
        $toPickUp = $this->createOrder();
        $toDeliver = $this->createOrder();
        $this->as($this->dispatcher)->assign($toPickUp, 'pickup', $this->courierA);
        $this->assign($toDeliver, 'pickup', $this->courierB);
        $this->as($this->courierB->user)->move($toDeliver, 'picked_up');
        $this->as($this->dispatcher)->assign($toDeliver, 'delivery', $this->courierA);

        $missions = $this->as($this->courierA->user)->getJson('/api/v1/courier/missions')->assertOk()->json('data');

        $this->assertSame(['pickup', 'delivery'], array_column($missions, 'type'));
        $this->assertSame($toPickUp->tracking_code, $missions[0]['order']['tracking_code']);
        $this->assertArrayNotHasKey('delivery_code', $missions[1]['order']); // jamais communiqué au livreur
    }

    public function test_bulk_assignment_reports_partial_errors(): void
    {
        $a = $this->createOrder();
        $b = $this->createOrder();
        $cancelled = $this->createOrder();
        $this->as($this->merchantUser)->move($cancelled, 'cancelled')->assertOk();

        $result = $this->as($this->dispatcher)->postJson('/api/v1/orders/bulk-assign', [
            'order_ids' => [$a->id, $b->id, $cancelled->id, 999999],
            'type' => 'pickup',
            'courier_id' => $this->courierA->id,
        ])->assertOk()->json('data');

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $result['assigned']);
        $this->assertArrayHasKey($cancelled->id, $result['errors']);
        $this->assertSame('Course introuvable.', $result['errors'][999999]);
    }

    public function test_bulk_confirmation_validates_pending_orders(): void
    {
        $a = $this->createOrder();
        $b = $this->createOrder();
        $cancelled = $this->createOrder();
        $this->as($this->merchantUser)->move($cancelled, 'cancelled')->assertOk();

        // Réservé au dispatch
        $this->as($this->merchantUser)->postJson('/api/v1/orders/bulk-confirm', ['order_ids' => [$a->id]])->assertForbidden();
        $this->as($this->courierA->user)->postJson('/api/v1/orders/bulk-confirm', ['order_ids' => [$a->id]])->assertForbidden();

        $result = $this->as($this->dispatcher)->postJson('/api/v1/orders/bulk-confirm', [
            'order_ids' => [$a->id, $b->id, $cancelled->id, 999999],
        ])->assertOk()->json('data');

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $result['confirmed']);
        $this->assertStringContainsString('pas à valider', $result['errors'][$cancelled->id]);
        $this->assertSame('Course introuvable.', $result['errors'][999999]);
        $this->assertSame('confirmed', $a->fresh()->status->value);
        $this->assertSame('cancelled', $cancelled->fresh()->status->value);
        // Journal : même événement qu'une validation à l'unité
        $this->assertTrue($a->events()->where('to_status', 'confirmed')->where('actor_id', $this->dispatcher->id)->exists());

        // Déjà validée : signalée, pas revalidée
        $again = $this->postJson('/api/v1/orders/bulk-confirm', ['order_ids' => [$a->id]])->json('data');
        $this->assertSame([], $again['confirmed']);
        $this->assertArrayHasKey($a->id, $again['errors']);
    }

    public function test_delivery_code_is_required_when_the_company_demands_it(): void
    {
        $this->company->update(['require_delivery_code' => true]);
        $order = $this->createOrder();
        $this->as($this->dispatcher)->assign($order, 'pickup', $this->courierA);
        $this->assign($order, 'delivery', $this->courierA);
        $this->as($this->courierA->user)->move($order, 'picked_up');
        $this->move($order, 'out_for_delivery');

        $this->move($order, 'delivered', ['delivery_code' => $order->delivery_code === '0000' ? '1111' : '0000'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.delivery_code.0', 'Code de livraison incorrect.');

        $this->move($order, 'delivered', ['delivery_code' => $order->fresh()->delivery_code])->assertOk();
    }

    public function test_the_journal_is_immutable(): void
    {
        $event = $this->createOrder()->events()->first();

        $this->expectException(LogicException::class);
        $event->update(['note' => 'falsifié']);
    }

    public function test_the_journal_cannot_be_deleted(): void
    {
        $event = $this->createOrder()->events()->first();

        $this->expectException(LogicException::class);
        $event->delete();
    }

    public function test_cross_company_orders_are_not_found(): void
    {
        $order = $this->createOrder();
        $outsider = User::factory()->withRole(Role::Admin)->create();

        $this->as($outsider)->getJson("/api/v1/orders/{$order->id}")->assertNotFound();
        $this->assertSame(0, OrderEvent::where('actor_id', $outsider->id)->count());
    }
}
