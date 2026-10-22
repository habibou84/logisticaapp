<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\IncidentReason;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderAlert;
use App\Services\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

class OrderIncidentTest extends TestCase
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

    private function reason(string $code): int
    {
        return IncidentReason::where('code', $code)->value('id');
    }

    /**
     * Course récupérée par le livreur A, en chemin avec le livreur B.
     */
    private function orderOutForDelivery(array $data = []): Order
    {
        $order = $this->createOrder($data);
        $this->as($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->as($this->courierA->user)->move($order, 'picked_up')->assertOk();
        $this->sendOut($order);

        return $order->fresh();
    }

    private function sendOut(Order $order): void
    {
        $this->as($this->dispatcher)
            ->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])
            ->assertOk();
        $this->as($this->courierB->user)->move($order, 'out_for_delivery')->assertOk();
    }

    public function test_failure_requires_a_reason_and_counts_an_attempt(): void
    {
        $order = $this->orderOutForDelivery();

        $this->move($order, 'delivery_failed')->assertJsonValidationErrors('incident_reason_id');

        $this->move($order, 'delivery_failed', [
            'incident_reason_id' => $this->reason('unreachable'),
            'note' => 'Appelé 3 fois',
        ])->assertOk()
            ->assertJsonPath('data.status', 'delivery_failed')
            ->assertJsonPath('data.attempts_count', 1)
            ->assertJsonPath('data.last_incident.label', 'Destinataire injoignable');

        $event = $order->events()->latest('id')->first();
        $this->assertSame('incident', $event->type->value);
        $this->assertSame('Appelé 3 fois', $event->note);
        $this->assertSame(1, $order->recipient->fresh()->failed_count);
    }

    public function test_postponement_reason_becomes_a_dated_reschedule_without_counting_an_attempt(): void
    {
        $order = $this->orderOutForDelivery();
        $date = today()->addDays(2)->toDateString();

        $this->move($order, 'delivery_failed', ['incident_reason_id' => $this->reason('postponed')])
            ->assertJsonValidationErrors('rescheduled_to');

        $this->move($order, 'delivery_failed', ['incident_reason_id' => $this->reason('postponed'), 'rescheduled_to' => $date])
            ->assertOk()
            ->assertJsonPath('data.status', 'rescheduled')
            ->assertJsonPath('data.delivery.scheduled_date', $date)
            ->assertJsonPath('data.attempts_count', 0);
    }

    public function test_reschedule_date_cannot_be_in_the_past(): void
    {
        $order = $this->orderOutForDelivery();

        $this->move($order, 'rescheduled', ['rescheduled_to' => today()->subDay()->toDateString()])
            ->assertJsonPath('errors.rescheduled_to.0', 'La date de report ne peut pas être passée.');
    }

    public function test_merchant_reacts_to_an_incident_by_fixing_the_address_and_rescheduling(): void
    {
        $order = $this->orderOutForDelivery();
        $this->move($order, 'delivery_failed', ['incident_reason_id' => $this->reason('wrong_address')]);

        $this->as($this->merchantUser);
        $this->patchJson("/api/v1/orders/{$order->id}", [
            'delivery_address' => 'Yopougon Maroc, près du marché',
            'recipient_phone2' => '0101010101',
        ])->assertOk()->assertJsonPath('data.delivery.address', 'Yopougon Maroc, près du marché');

        $this->move($order, 'rescheduled', ['rescheduled_to' => today()->addDay()->toDateString()])
            ->assertOk()
            ->assertJsonPath('data.status', 'rescheduled');

        $edit = $order->events()->where('type', 'edited')->first();
        $this->assertSame('Yopougon Siporex', $edit->meta['changes']['delivery_address']['from']);

        // Nouvelle tentative
        $this->sendOut($order);
        $this->move($order, 'delivered')->assertOk();
    }

    public function test_after_max_attempts_only_a_return_is_possible(): void
    {
        $this->company->update(['default_max_attempts' => 2]);
        $order = $this->orderOutForDelivery();

        $this->move($order, 'delivery_failed', ['incident_reason_id' => $this->reason('absent')])->assertOk();
        $this->sendOut($order);
        $this->move($order, 'delivery_failed', ['incident_reason_id' => $this->reason('absent')])
            ->assertOk()
            ->assertJsonPath('data.attempts_count', 2)
            ->assertJsonPath('data.return_requested', true);

        $this->as($this->dispatcher)
            ->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])
            ->assertJsonPath('message', 'Nombre maximal de tentatives atteint : organisez le retour du colis.');

        $ids = collect($this->getJson('/api/v1/orders?queue=to_return')->json('data'))->pluck('id');
        $this->assertContains($order->id, $ids);

        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'return', 'courier_id' => $this->courierA->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'return_assigned');
        $this->as($this->courierA->user);
        $this->move($order, 'returning')->assertOk();
        $this->move($order, 'returned')->assertOk()->assertJsonPath('data.status', 'returned');
    }

    public function test_refusal_triggers_a_return_request(): void
    {
        $order = $this->orderOutForDelivery();

        $this->move($order, 'delivery_failed', ['incident_reason_id' => $this->reason('refused')])
            ->assertJsonPath('data.return_requested', true);
    }

    public function test_merchant_requests_a_return(): void
    {
        $order = $this->orderOutForDelivery();
        $this->move($order, 'delivery_failed', ['incident_reason_id' => $this->reason('no_money')]);

        $this->as($this->merchantUser)
            ->postJson("/api/v1/orders/{$order->id}/return-request", ['note' => 'Le client a annulé'])
            ->assertOk()
            ->assertJsonPath('data.return_requested', true);

        $this->assertSame('return_requested', $order->events()->latest('id')->first()->type->value);
    }

    public function test_return_decided_by_the_agency_names_who_decided(): void
    {
        $order = $this->orderOutForDelivery();
        $this->move($order, 'delivery_failed', ['incident_reason_id' => $this->reason('no_money')]);
        Notification::fake();

        app(OrderService::class)->requestReturn($this->dispatcher, $order->fresh());

        Notification::assertSentTo($this->admin, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'return_requested'
            && $n->body === $this->dispatcher->name.' a demandé le retour du colis.');
    }

    public function test_pickup_failure_puts_the_order_back_to_confirmed(): void
    {
        $order = $this->createOrder();
        $this->as($this->dispatcher)
            ->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);

        $this->as($this->courierA->user);
        $this->move($order, 'confirmed', ['incident_reason_id' => $this->reason('unreachable')])
            ->assertJsonPath('errors.incident_reason_id.0', 'Ce motif ne s\'applique pas à cette étape.');

        $this->move($order, 'confirmed', ['incident_reason_id' => $this->reason('merchant_unavailable')])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.pickup_courier', null);

        $this->assertSame('failed', $order->assignments()->first()->status->value);
    }

    public function test_merchant_cancels_only_before_pickup(): void
    {
        $order = $this->createOrder();
        $this->as($this->merchantUser)->move($order, 'cancelled', ['cancel_reason' => 'Client injoignable'])
            ->assertOk()
            ->assertJsonPath('data.cancel_reason', 'Client injoignable');

        $picked = $this->orderOutForDelivery();
        $this->as($this->merchantUser)->move($picked, 'cancelled')->assertUnprocessable();
    }

    public function test_cancellation_cancels_active_missions(): void
    {
        $order = $this->createOrder();
        $this->as($this->dispatcher)
            ->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);

        $this->move($order, 'cancelled')->assertOk();

        $this->assertSame('cancelled', $order->assignments()->first()->status->value);
        $this->as($this->courierA->user)->getJson('/api/v1/courier/missions')->assertJsonCount(0, 'data');
    }

    public function test_pickup_fields_are_locked_once_picked_up(): void
    {
        $order = $this->orderOutForDelivery();
        $this->move($order, 'delivery_failed', ['incident_reason_id' => $this->reason('absent')]);

        $this->as($this->merchantUser)
            ->patchJson("/api/v1/orders/{$order->id}", ['items_amount' => 1])
            ->assertUnprocessable();
    }

    public function test_changing_the_delivery_zone_reprices_the_order(): void
    {
        $order = $this->createOrder(['fee_payer' => 'recipient']);
        $this->assertSame(11500, $order->cod_amount);

        $this->as($this->merchantUser)
            ->patchJson("/api/v1/orders/{$order->id}", ['delivery_zone_id' => $this->cocody->id])
            ->assertOk()
            ->assertJsonPath('data.amounts.delivery_fee', 1000)
            ->assertJsonPath('data.amounts.cod_amount', 11000);
    }

    public function test_orders_out_for_delivery_cannot_be_edited(): void
    {
        $order = $this->orderOutForDelivery();

        $this->as($this->merchantUser)
            ->patchJson("/api/v1/orders/{$order->id}", ['delivery_address' => 'ailleurs'])
            ->assertUnprocessable();
    }

    public function test_failed_deliveries_wait_for_a_decision(): void
    {
        $order = $this->orderOutForDelivery();
        $this->move($order, 'delivery_failed', ['incident_reason_id' => $this->reason('absent')]);
        $this->assertSame(OrderStatus::DeliveryFailed, $order->fresh()->status);

        // Un échec attend d'abord une décision (relivrer, retourner) avant de revenir dans « À livrer »
        $toDeliver = collect($this->as($this->dispatcher)->getJson('/api/v1/orders?queue=to_deliver')->json('data'))->pluck('id');
        $toDecide = collect($this->getJson('/api/v1/orders?queue=to_decide')->json('data'))->pluck('id');

        $this->assertNotContains($order->id, $toDeliver);
        $this->assertContains($order->id, $toDecide);
    }
}
