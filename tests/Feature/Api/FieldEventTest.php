<?php

namespace Tests\Feature\Api;

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Models\CourierAbsence;
use App\Models\CourierMessage;
use App\Models\FieldEvent;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Notifications\FieldEventAlert;
use App\Services\Couriers\FieldReportReminders;
use App\Services\FieldEvents\FieldEventStats;
use App\Services\Orders\OrderDispatcher;
use App\Services\Orders\OrderWorkflow;
use App\Services\Ratings\CourierScores;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Événements terrain : signalement du livreur, alertes, prise en charge, reprise, effets automatiques
 * et actions de l'équipe sur ses courses ; réglages de l'entreprise.
 */
class FieldEventTest extends TestCase
{
    use BuildsDeliveryWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo('2026-11-23 10:00:00');
        $this->courierA->update(['is_available' => true]);
        $this->courierB->update(['is_available' => true, 'current_lat' => 5.36, 'current_lng' => -4.01]);
        Storage::fake('local');
    }

    private function settings(array $values): void
    {
        $this->company->update(['field_event_settings' => $values]);
        foreach ([$this->admin, $this->dispatcher, $this->courierA->user, $this->courierB->user] as $user) {
            $user->unsetRelation('company');
        }
        $this->courierA->unsetRelation('company');
        $this->courierA->user->unsetRelation('courier');
    }

    /** Ramassage confié à Koffi (livreur A) */
    private function pickupFor(): Order
    {
        $order = $this->createOrder();
        app(OrderDispatcher::class)->assign($this->dispatcher, $order, AssignmentType::Pickup, $this->courierA);

        return $order->fresh();
    }

    /** Colis en route chez Koffi */
    private function outForDelivery(): Order
    {
        $order = $this->createOrder();
        $order->forceFill(['status' => OrderStatus::PickedUp])->save();
        app(OrderDispatcher::class)->assign($this->dispatcher, $order, AssignmentType::Delivery, $this->courierA);
        app(OrderWorkflow::class)->transition($this->dispatcher, $order->fresh(), OrderStatus::OutForDelivery);

        return $order->fresh();
    }

    private function report(array $data = []): FieldEvent
    {
        Sanctum::actingAs($this->courierA->user);
        $id = $this->post('/api/v1/courier/field-events', [
            'type' => 'breakdown', 'capacity' => 'blocked', 'blocked_minutes' => 60, 'lat' => 5.35, 'lng' => -4.0, ...$data,
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        return FieldEvent::findOrFail($id);
    }

    public function test_courier_reports_a_breakdown_and_the_team_is_alerted(): void
    {
        Notification::fake();
        $pickup = $this->pickupFor();
        $other = $this->createOrder();
        Sanctum::actingAs($this->courierA->user);

        $setup = $this->getJson('/api/v1/courier/field-events/setup')->assertOk();
        $this->assertSame([$pickup->id], array_column($setup->json('data.orders'), 'id'));
        $this->assertContains('breakdown', array_column($setup->json('data.types'), 'key'));

        $response = $this->post('/api/v1/courier/field-events', [
            'client_uuid' => 'abc-1', 'type' => 'breakdown', 'capacity' => 'blocked', 'blocked_minutes' => 60,
            'note' => 'Crevaison à Cocody', 'lat' => 5.35, 'lng' => -4.0, 'cost' => 2000,
            'order_ids' => [$pickup->id, $other->id],
            'photos' => [UploadedFile::fake()->image('pneu.jpg')],
            'voice' => UploadedFile::fake()->create('note.webm', 20, 'audio/webm'), 'voice_duration' => 12,
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.type_label', 'Panne')
            ->assertJsonPath('data.severity', 'blocking')
            ->assertJsonPath('data.excused', true)
            ->assertJsonPath('data.cost', 2000);

        $event = FieldEvent::findOrFail($response->json('data.id'));
        // Seulement les courses qu'il a en main ; photo et note vocale gardées
        $this->assertSame([$pickup->id], $event->orders()->pluck('orders.id')->all());
        $this->assertSame(['photo', 'voice'], $event->files()->orderBy('id')->pluck('kind')->all());
        $this->assertEqualsWithDelta(now()->addHour()->timestamp, $event->blocked_until->timestamp, 2);
        // Noté dans l'historique de la course, sans apparaître dans les remontées des courses
        $this->assertTrue($pickup->events()->where('meta->field_event_id', $event->id)->exists());
        $this->getJson('/api/v1/courier/field-events/'.$event->id.'/files/'.$event->files()->first()->id)->assertOk();

        // Livreur bloqué : hors service ; dispatch prévenu (pas les administrateurs pour une panne)
        $this->assertFalse($this->courierA->fresh()->is_available);
        Notification::assertSentTo($this->dispatcher, FieldEventAlert::class, fn ($n) => str_contains($n->title, 'Panne') && str_contains($n->body, '1 course touchée'));
        Notification::assertNotSentTo($this->admin, FieldEventAlert::class);

        // Renvoyé (hors connexion) : un seul événement
        $this->post('/api/v1/courier/field-events', ['client_uuid' => 'abc-1', 'type' => 'breakdown', 'capacity' => 'blocked'], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.id', $event->id);
        $this->assertSame(1, FieldEvent::count());

        // Accident (urgent) : administrateurs prévenus aussi
        $this->post('/api/v1/courier/field-events', ['type' => 'accident', 'capacity' => 'stopped'], ['Accept' => 'application/json'])->assertCreated();
        Notification::assertSentTo($this->admin, FieldEventAlert::class);

        // Visible par l'équipe, l'événement urgent en premier
        Sanctum::actingAs($this->dispatcher);
        $list = $this->getJson('/api/v1/field-events')->assertOk()->assertJsonPath('open', 2);
        $this->assertSame(['accident', 'breakdown'], array_column($list->json('data'), 'type'));
        $this->getJson('/api/v1/couriers/map')->assertOk()->assertJsonPath('data.0.field_event', fn ($e) => $e === null || isset($e['label']));
    }

    public function test_settings_drive_types_and_rules(): void
    {
        Sanctum::actingAs($this->admin);
        $types = [
            ['key' => 'breakdown', 'label' => 'Panne', 'icon' => '🛵', 'severity' => 'blocking', 'photo' => true, 'gps' => true, 'excuse' => false, 'active' => true],
            ['label' => 'Pneu crevé', 'icon' => '🛞', 'severity' => 'hindrance', 'active' => true],
            ['key' => 'other', 'label' => 'Autre', 'severity' => 'info', 'active' => false],
        ];
        $saved = $this->patchJson("/api/v1/companies/{$this->company->id}", ['field_event_settings' => ['types' => $types, 'pause_courier' => false]])
            ->assertOk()->json('data.field_event_settings');
        $this->assertSame(['breakdown', 'pneu_creve', 'other'], array_column($saved['types'], 'key'));
        $this->assertFalse($saved['pause_courier']);
        $this->courierA->user->unsetRelation('company');
        $this->courierA->unsetRelation('company');

        Sanctum::actingAs($this->courierA->user);
        $this->assertSame(['breakdown', 'pneu_creve'], array_column($this->getJson('/api/v1/courier/field-events/setup')->json('data.types'), 'key'));
        // Photo obligatoire pour une panne ; type désactivé refusé
        $this->post('/api/v1/courier/field-events', ['type' => 'breakdown', 'capacity' => 'blocked'], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/v1/courier/field-events', ['type' => 'other', 'capacity' => 'continue'], ['Accept' => 'application/json'])->assertUnprocessable();
        $event = $this->report(['photos' => [UploadedFile::fake()->image('moto.jpg')]]);
        // Pas de pause : le livreur reste en service ; retards non excusés pour ce type
        $this->assertTrue($this->courierA->fresh()->is_available);
        $this->assertFalse($event->excused);

        $this->settings(['enabled' => false]);
        Sanctum::actingAs($this->courierA->user);
        $this->post('/api/v1/courier/field-events', ['type' => 'pneu_creve', 'capacity' => 'continue'], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_take_message_resolve_and_resume(): void
    {
        Notification::fake();
        $event = $this->report(['type' => 'health', 'capacity' => 'stopped']);
        // Journée arrêtée : absence du jour au planning
        $absence = CourierAbsence::where('courier_id', $this->courierA->id)->sole();
        $this->assertSame('Événement terrain : Santé', $absence->reason);

        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/field-events/{$event->id}/take")->assertOk()->assertJsonPath('data.status', 'taken')->assertJsonPath('data.taken_by', $this->dispatcher->name);
        Notification::assertSentTo($this->courierA->user, FieldEventAlert::class, fn ($n) => $n->kind === 'field_event_taken');
        $this->postJson("/api/v1/field-events/{$event->id}/messages", ['body' => 'Rentrez vous reposer'])->assertOk()->assertJsonCount(1, 'data.messages');

        // Le livreur répond, puis reprend : en service, absence retirée, équipe prévenue
        Sanctum::actingAs($this->courierA->user);
        $this->getJson("/api/v1/courier/field-events/{$event->id}")->assertOk()->assertJsonPath('data.messages.0.body', 'Rentrez vous reposer');
        $this->postJson("/api/v1/courier/field-events/{$event->id}/messages", ['body' => 'Ça va mieux'])->assertOk();
        Notification::assertSentTo($this->dispatcher, FieldEventAlert::class, fn ($n) => $n->kind === 'field_event_message');
        $this->postJson("/api/v1/courier/field-events/{$event->id}/resume")->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->assertTrue($this->courierA->fresh()->is_available);
        $this->assertNull(CourierAbsence::find($absence->id));
        Notification::assertSentTo($this->dispatcher, FieldEventAlert::class, fn ($n) => $n->kind === 'field_event_resumed');

        // Pas l'événement d'un autre livreur
        Sanctum::actingAs($this->courierB->user);
        $this->getJson("/api/v1/courier/field-events/{$event->id}")->assertNotFound();

        // L'équipe peut rouvrir, puis résoudre avec un commentaire
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/field-events/{$event->id}/reopen")->assertOk()->assertJsonPath('data.status', 'taken');
        $this->postJson("/api/v1/field-events/{$event->id}/resolve", ['resolution' => 'Repos jusqu\'à demain'])->assertOk()
            ->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.resolution', 'Repos jusqu\'à demain');
        $this->getJson('/api/v1/field-events')->assertJsonPath('open', 0)->assertJsonCount(0, 'data');
    }

    public function test_team_moves_the_courses_to_another_courier(): void
    {
        Notification::fake();
        $pickup = $this->pickupFor();
        $route = $this->outForDelivery();
        $event = $this->report(['lat' => 5.35, 'lng' => -4.0]);

        Sanctum::actingAs($this->dispatcher);
        $candidates = $this->getJson("/api/v1/field-events/{$event->id}/candidates")->assertOk()->json('data');
        $this->assertSame([$this->courierB->id], array_column($candidates, 'id'));
        $this->assertNotNull($candidates[0]['distance_km']);

        $result = $this->postJson("/api/v1/field-events/{$event->id}/reassign", ['courier_id' => $this->courierB->id, 'order_ids' => [$pickup->id, $route->id]])
            ->assertOk()->json('result');
        $this->assertSame([$pickup->id, $route->id], $result['assigned']);
        $this->assertSame(1, $result['handover']);

        $this->assertSame([$this->courierB->id, OrderStatus::PickupAssigned], [$pickup->fresh()->pickup_courier_id, $pickup->fresh()->status]);
        // Colis en route : livraison arrêtée (pas une tentative), confiée à Awa, qui va le chercher chez Koffi
        $route = $route->fresh();
        $this->assertSame([$this->courierB->id, OrderStatus::DeliveryAssigned, 0], [$route->delivery_courier_id, $route->status, $route->attempts_count]);
        $this->assertSame($this->courierA->id, $route->held_by_courier_id);
        $message = CourierMessage::where('courier_id', $this->courierB->id)->sole();
        $this->assertStringContainsString('Récupérez 1 colis auprès de Koffi Ramasseur', $message->body);
        $this->assertStringContainsString('maps.google.com/?q=5.35', $message->body);
        $this->assertSame([$pickup->id, $route->id], $event->fresh()->effects['reassigned']);
    }

    public function test_automatic_release_and_client_notice(): void
    {
        $this->settings(['release_pickups' => true, 'notify_clients' => true]);
        $pickup = $this->pickupFor();
        $event = $this->report();

        // Ramassage retiré au livreur bloqué ; client prévenu d'un retard (message neutre)
        $this->assertNull($pickup->fresh()->pickup_courier_id);
        $this->assertSame(OrderStatus::Confirmed, $pickup->fresh()->status);
        $this->assertSame([$pickup->id], $event->fresh()->effects['released']);
        $message = OutboundMessage::where('template_name', 'course_retardee')->sole();
        $this->assertStringContainsString('aura du retard', $message->body);

        // Bouton « Prévenir les clients » : pas deux fois le même client
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/field-events/{$event->id}/notify-clients")->assertOk()->assertJsonPath('result.notified', 0);
        $this->assertSame(1, OutboundMessage::where('template_name', 'course_retardee')->count());
    }

    public function test_unhandled_events_are_reminded(): void
    {
        Notification::fake();
        $this->company->update(['field_alert_reminder_minutes' => 10]);
        $this->report();
        $runner = app(FieldReportReminders::class);

        $this->travel(11)->minutes();
        $this->assertSame(1, $runner->run());
        Notification::assertSentTo($this->dispatcher, FieldEventAlert::class, fn ($n) => $n->kind === 'field_event_reminder');
        $this->assertSame(0, $runner->run());

        $this->travel(20)->minutes();
        $this->assertSame(1, $runner->run());
        Notification::assertSentTo($this->admin, FieldEventAlert::class, fn ($n) => $n->kind === 'field_event_reminder');
    }

    public function test_excused_delays_stats_and_weekly_line(): void
    {
        // Ramassage en retard pendant une panne : pas compté dans la note automatique
        $order = $this->pickupFor();
        $event = $this->report(['cost' => 3000]);
        $order->assignments()->where('type', 'pickup')->update(['late_alerted_at' => now(), 'status' => 'completed', 'completed_at' => now()]);
        $scores = app(CourierScores::class);
        $pickup = collect($scores->for($this->courierA)['auto']['indicators'] ?? [])->firstWhere('key', 'pickup_on_time');
        $this->assertNull($pickup['value'], 'ramassage excusé : ni à l\'heure ni en retard');
        $event->forceFill(['excused' => false])->save();
        $pickup = collect($scores->for($this->courierA)['auto']['indicators'] ?? [])->firstWhere('key', 'pickup_on_time');
        $this->assertSame(0, $pickup['value']);

        // Le livreur reprend une heure plus tard : 60 min d'immobilisation
        $this->travel(60)->minutes();
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/courier/field-events/{$event->id}/resume")->assertOk();

        Sanctum::actingAs($this->dispatcher);
        $stats = $this->getJson('/api/v1/field-events/stats?from=2026-11-01&to=2026-11-30')->assertOk()->json('data');
        $this->assertSame(1, $stats['totals']['events']);
        $this->assertSame(60, $stats['totals']['immobilised_minutes']);
        $this->assertSame(3000, $stats['totals']['cost']);
        $this->assertSame('Koffi Ramasseur', $stats['by_courier'][0]['name']);
        $this->assertSame(60, $stats['totals']['resolve_minutes']);

        $line = app(FieldEventStats::class)->weeklyLine($this->company->id, '2026-11-23', '2026-11-29');
        $this->assertSame('1 événement(s) terrain (Panne 1), immobilisation 1 h', $line);
    }
}
