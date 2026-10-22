<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\Company;
use App\Models\IncidentReason;
use App\Models\Order;
use App\Notifications\OrderAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Remontées terrain : notes et problèmes des livreurs, signalés en direct au dispatch,
 * consultables et marqués comme traités.
 */
class FieldReportTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();

        $this->order = $this->createOrder(['recipient_name' => 'Awa Koné']);
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$this->order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$this->order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$this->order->id}/status", ['status' => 'picked_up']);
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/orders/{$this->order->id}/status", ['status' => 'out_for_delivery']);
    }

    private function reason(): IncidentReason
    {
        return IncidentReason::where('applies_to', '!=', 'pickup')->where('requires_date', false)->first();
    }

    public function test_courier_notes_and_problems_alert_dispatch_with_a_field_flag(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->courierB->user);

        // Note interne du livreur (invisible pour le marchand) : le dispatch est quand même prévenu
        $this->postJson("/api/v1/orders/{$this->order->id}/notes", ['note' => 'Portail fermé, j\'attends', 'visible_to_merchant' => false])->assertSuccessful();
        Notification::assertSentTo($this->dispatcher, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'note'
            && $n->toArray($this->dispatcher)['field'] === true
            && $n->toArray($this->dispatcher)['severity'] === 'note');
        Notification::assertNotSentTo($this->merchantUser, OrderAlert::class);

        $this->postJson("/api/v1/orders/{$this->order->id}/status", ['status' => 'delivery_failed', 'incident_reason_id' => $this->reason()->id, 'note' => 'Client absent'])->assertOk();
        Notification::assertSentTo($this->admin, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'incident'
            && $n->toArray($this->admin)['severity'] === 'alert');

        // Une note du marchand n'est pas une remontée terrain
        Notification::fake();
        Sanctum::actingAs($this->merchantUser);
        $this->postJson("/api/v1/orders/{$this->order->id}/notes", ['note' => 'Appelez avant'])->assertSuccessful();
        Notification::assertSentTo($this->dispatcher, OrderAlert::class, fn (OrderAlert $n) => ! isset($n->toArray($this->dispatcher)['field']));
    }

    public function test_reports_are_listed_filtered_and_handled(): void
    {
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/orders/{$this->order->id}/notes", ['note' => 'Portail fermé'])->assertSuccessful();
        $this->postJson("/api/v1/orders/{$this->order->id}/status", ['status' => 'delivery_failed', 'incident_reason_id' => $this->reason()->id])->assertOk();

        Sanctum::actingAs($this->dispatcher);
        // Une note du dispatch n'apparaît pas dans les remontées
        $this->postJson("/api/v1/orders/{$this->order->id}/notes", ['note' => 'Rappeler le client'])->assertSuccessful();

        $list = $this->getJson('/api/v1/field-reports')->assertOk();
        $list->assertJsonCount(2, 'data')
            ->assertJsonPath('open.total', 2)
            ->assertJsonPath('open.incident', 1)
            ->assertJsonPath('open.note', 1)
            ->assertJsonPath('data.0.kind', 'incident')
            ->assertJsonPath('data.0.incident', $this->reason()->label)
            ->assertJsonPath('data.0.courier.name', 'Awa Livreuse')
            ->assertJsonPath('data.0.courier.phone', $this->courierB->user->phone)
            ->assertJsonPath('data.0.order.tracking_code', $this->order->tracking_code)
            ->assertJsonPath('data.1.note', 'Portail fermé');

        $this->getJson('/api/v1/field-reports?kind=note')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/field-reports?search=portail')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/field-reports?search=awa%20kon')->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/field-reports?courier_id={$this->courierA->id}")->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/field-reports?from='.now()->addDay()->toDateString())->assertJsonCount(0, 'data');

        $incidentId = $list->json('data.0.id');
        $this->postJson("/api/v1/field-reports/{$incidentId}/handle", ['comment' => 'Client rappelé, livraison demain'])->assertOk()
            ->assertJsonPath('data.review.handled_by', $this->dispatcher->name)
            ->assertJsonPath('data.review.comment', 'Client rappelé, livraison demain')
            ->assertJsonPath('open.total', 1);

        $this->getJson('/api/v1/field-reports')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/field-reports?state=handled')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/field-reports?state=all')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/field-reports/counts')->assertJsonPath('data.total', 1);

        $this->postJson("/api/v1/field-reports/{$incidentId}/reopen")->assertOk()->assertJsonPath('data.review', null)->assertJsonPath('open.total', 2);

        // Une note du dispatch ne se traite pas comme une remontée
        $staffNote = $this->order->events()->where('note', 'Rappeler le client')->first();
        $this->postJson("/api/v1/field-reports/{$staffNote->id}/handle")->assertStatus(422);
    }

    public function test_access_is_limited_to_dispatch_and_company(): void
    {
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/orders/{$this->order->id}/notes", ['note' => 'Note'])->assertSuccessful();
        $eventId = $this->order->events()->latest('id')->value('id');

        $this->getJson('/api/v1/field-reports')->assertForbidden();
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/field-reports')->assertForbidden();

        $other = Company::factory()->create();
        Sanctum::actingAs($this->userWithRole(Role::Dispatcher, [], $other));
        $this->getJson('/api/v1/field-reports')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/field-reports/{$eventId}/handle")->assertNotFound();
    }
}
