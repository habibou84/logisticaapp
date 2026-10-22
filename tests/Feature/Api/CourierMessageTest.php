<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\CourierMessage;
use App\Models\IncidentReason;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Notifications\OrderAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Réponses du dispatch aux livreurs et relance automatique des problèmes sans suite.
 */
class CourierMessageTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Carbon::setTestNow('2026-10-09 10:00:00');

        $this->order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$this->order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$this->order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$this->order->id}/status", ['status' => 'picked_up']);
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/orders/{$this->order->id}/status", ['status' => 'out_for_delivery']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function failDelivery(): OrderEvent
    {
        Sanctum::actingAs($this->courierB->user);
        $reason = IncidentReason::where('applies_to', '!=', 'pickup')->where('requires_date', false)->first();
        $this->postJson("/api/v1/orders/{$this->order->id}/status", ['status' => 'delivery_failed', 'incident_reason_id' => $reason->id])->assertOk();

        return $this->order->events()->latest('id')->first();
    }

    public function test_dispatch_replies_to_a_field_report_and_the_courier_acknowledges(): void
    {
        $report = $this->failDelivery();
        Notification::fake();

        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$this->order->id}/courier-messages", [
            'body' => 'Attends 5 minutes, j\'appelle le client', 'reply_to_event_id' => $report->id, 'mark_handled' => true,
        ])->assertCreated()->assertJsonPath('data.courier_id', $this->courierB->id)->assertJsonPath('data.sender', $this->dispatcher->name);

        // Le livreur est prévenu en direct, avec sa mission
        Notification::assertSentTo($this->courierB->user, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'dispatch_message'
            && $n->toArray($this->courierB->user)['dispatch_message'] === true
            && $n->toArray($this->courierB->user)['assignment_id'] !== null);
        Notification::assertNotSentTo($this->courierA->user, OrderAlert::class);

        // Remontée traitée, consigne tracée au journal (interne)
        $this->getJson('/api/v1/field-reports?state=handled')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.replies.0.body', 'Attends 5 minutes, j\'appelle le client');
        $journal = $this->order->events()->latest('id')->first();
        $this->assertStringStartsWith('Consigne à Awa Livreuse', $journal->note);
        $this->assertFalse($journal->visible_to_merchant);

        // Côté livreur : non lu sur la mission, lu à l'ouverture, puis « compris »
        Sanctum::actingAs($this->courierB->user);
        // Après l'échec, la mission est terminée : elle figure dans l'historique du jour
        $missions = $this->getJson('/api/v1/courier/missions?history=1')->assertOk()->json('data');
        $this->assertSame(1, collect($missions)->firstWhere('order_id', $this->order->id)['unread_messages']);

        $messages = $this->getJson("/api/v1/courier/messages?order_id={$this->order->id}")->assertOk()->json('data');
        $this->assertNotNull(CourierMessage::first()->read_at);
        $this->postJson("/api/v1/courier/messages/{$messages[0]['id']}/ack")->assertOk()->assertJsonPath('data.acknowledged_at', now()->toJSON());

        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/courier/messages/{$messages[0]['id']}/ack")->assertNotFound();

        Sanctum::actingAs($this->dispatcher);
        $this->getJson("/api/v1/orders/{$this->order->id}/courier-messages")->assertOk()->assertJsonPath('data.0.acknowledged_at', now()->toJSON());
    }

    public function test_free_message_goes_to_the_courier_on_mission_and_is_restricted(): void
    {
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$this->order->id}/courier-messages", ['body' => 'Livre au second numéro'])
            ->assertCreated()->assertJsonPath('data.courier_id', $this->courierB->id);
        $this->postJson("/api/v1/orders/{$this->order->id}/courier-messages", ['body' => ''])->assertJsonValidationErrors('body');

        // Livreur sans mission sur la course
        $other = $this->userWithRole(Role::Courier, ['name' => 'Moussa'])->courier;
        $this->postJson("/api/v1/orders/{$this->order->id}/courier-messages", ['body' => 'Test', 'courier_id' => $other->id])
            ->assertJsonValidationErrors('courier_id');

        Sanctum::actingAs($this->merchantUser);
        $this->postJson("/api/v1/orders/{$this->order->id}/courier-messages", ['body' => 'Test'])->assertForbidden();
    }

    public function test_unhandled_problems_are_reminded_to_dispatch_then_admins(): void
    {
        $this->company->update(['field_alert_reminder_minutes' => 10]);
        $report = $this->failDelivery();

        // Une note du livreur n'est jamais relancée
        $this->postJson("/api/v1/orders/{$this->order->id}/notes", ['note' => 'Info'])->assertSuccessful();

        Notification::fake();
        Carbon::setTestNow('2026-10-09 10:05:00');
        $this->artisan('field-reports:remind')->expectsOutput('0 relance(s) envoyée(s).');

        Carbon::setTestNow('2026-10-09 10:11:00');
        $this->artisan('field-reports:remind')->expectsOutput('1 relance(s) envoyée(s).');
        Notification::assertSentTo([$this->dispatcher, $this->admin], OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'field_reminder'
            && $n->toArray($this->dispatcher)['reminder'] === 1 && str_contains($n->title, $this->order->tracking_code));

        // Pas de nouvelle relance avant trois fois le délai, puis aux administrateurs seulement
        Notification::fake();
        Carbon::setTestNow('2026-10-09 10:20:00');
        $this->artisan('field-reports:remind')->expectsOutput('0 relance(s) envoyée(s).');
        Carbon::setTestNow('2026-10-09 10:31:00');
        $this->artisan('field-reports:remind')->expectsOutput('1 relance(s) envoyée(s).');
        Notification::assertSentTo($this->admin, OrderAlert::class, fn (OrderAlert $n) => $n->toArray($this->admin)['reminder'] === 2);
        Notification::assertNotSentTo($this->dispatcher, OrderAlert::class);

        Carbon::setTestNow('2026-10-09 11:30:00');
        $this->artisan('field-reports:remind')->expectsOutput('0 relance(s) envoyée(s).');
        $this->assertNotNull($report);
    }

    public function test_a_reply_or_a_disabled_setting_stops_reminders(): void
    {
        $report = $this->failDelivery();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$this->order->id}/courier-messages", ['body' => 'J\'appelle le client', 'reply_to_event_id' => $report->id])->assertCreated();

        Carbon::setTestNow('2026-10-09 10:30:00');
        $this->artisan('field-reports:remind')->expectsOutput('0 relance(s) envoyée(s).');

        // Relance désactivée par l'entreprise
        $this->putJson("/api/v1/companies/{$this->company->id}", ['field_alert_reminder_minutes' => 0])->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/companies/{$this->company->id}", ['field_alert_reminder_minutes' => 0])->assertOk()
            ->assertJsonPath('data.field_alert_reminder_minutes', 0);
        CourierMessage::query()->delete();
        $this->artisan('field-reports:remind')->expectsOutput('0 relance(s) envoyée(s).');
    }
}
