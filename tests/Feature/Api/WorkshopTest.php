<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\FieldEvent;
use App\Models\Mechanic;
use App\Models\Repair;
use App\Models\RepairPurchase;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLog;
use App\Notifications\FieldEventAlert;
use App\Notifications\RepairAlert;
use App\Services\Workshop\RepairDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Atelier : la panne signalée par le livreur est confiée automatiquement au mécanicien le plus proche, qui part,
 * arrive, diagnostique et clôt ; le carnet du véhicule et l'événement terrain suivent. Achats sur place soumis à
 * l'accord de l'administrateur, attribution à la main par l'équipe.
 */
class WorkshopTest extends TestCase
{
    use BuildsDeliveryWorld;
    use RefreshDatabase;

    private User $near;

    private User $far;

    private User $fleetManager;

    private Vehicle $moto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo('2026-11-23 10:00:00');
        Storage::fake('local');

        $this->near = $this->userWithRole(Role::Mechanic, ['name' => 'Moussa Mécano']);
        $this->far = $this->userWithRole(Role::Mechanic, ['name' => 'Yao Mécano']);
        $this->fleetManager = $this->userWithRole(Role::FleetManager, ['name' => 'Kouamé Parc']);
        $this->near->mechanic->update(['is_available' => true, 'current_lat' => 5.351, 'current_lng' => -4.001]);
        $this->far->mechanic->update(['is_available' => true, 'current_lat' => 5.40, 'current_lng' => -4.10]);

        $this->courierA->update(['is_available' => true]);
        $this->moto = Vehicle::create([
            'company_id' => $this->company->id, 'type' => 'moto', 'plate' => 'AB-4521-CI', 'odometer' => 18000,
            'status' => 'active', 'courier_id' => $this->courierA->id,
        ]);
    }

    private function report(array $data = []): FieldEvent
    {
        Sanctum::actingAs($this->courierA->user);
        $id = $this->post('/api/v1/courier/field-events', [
            'type' => 'breakdown', 'capacity' => 'blocked', 'blocked_minutes' => 60, 'lat' => 5.35, 'lng' => -4.0,
            'note' => 'Ça ne redémarre plus', ...$data,
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        return FieldEvent::findOrFail($id);
    }

    private function repair(): Repair
    {
        return Repair::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    public function test_breakdown_is_offered_to_the_nearest_available_mechanic(): void
    {
        Notification::fake();
        $this->userWithRole(Role::Mechanic, ['name' => 'Absent'])->mechanic->update(['is_available' => false, 'current_lat' => 5.35, 'current_lng' => -4.0]);

        $event = $this->report();
        $repair = $this->repair();

        $this->assertSame([Repair::OFFERED, $this->near->mechanic->id, $event->id, $this->moto->id, $this->courierA->id],
            [$repair->status, $repair->mechanic_id, $repair->field_event_id, $repair->vehicle_id, $repair->courier_id]);
        $this->assertSame('2026-11-23 10:05:00', $repair->offer_expires_at->toDateTimeString());
        Notification::assertSentTo($this->near, RepairAlert::class, fn (RepairAlert $n) => $n->kind === 'repair_offered');
        Notification::assertNotSentTo($this->far, RepairAlert::class);

        // Le mécanicien voit l'alerte et la fiche
        Sanctum::actingAs($this->near);
        $list = $this->getJson('/api/v1/mechanic/repairs')->assertOk()->json('data');
        $this->assertSame($repair->id, $list['offer']['id']);
        $this->assertSame('Panne', $list['offer']['title']);
        $this->assertSame('AB-4521-CI', $list['offer']['vehicle']['plate']);
        $this->assertSame(0.2, $list['offer']['distance_km']);
        $this->getJson("/api/v1/mechanic/repairs/{$repair->id}")->assertOk()->assertJsonPath('data.event.capacity_label', 'bloqué');

        // L'autre mécanicien ne la voit pas tant qu'elle est proposée à Moussa
        Sanctum::actingAs($this->far);
        $this->getJson("/api/v1/mechanic/repairs/{$repair->id}")->assertNotFound();
        $this->assertNull($this->getJson('/api/v1/mechanic/repairs')->json('data.offer'));
    }

    public function test_accept_takes_the_field_event_and_the_courier_sees_who_comes(): void
    {
        Notification::fake();
        $event = $this->report();
        $repair = $this->repair();

        Sanctum::actingAs($this->near);
        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/accept", ['at' => '2026-11-23T09:59:30+00:00'])
            ->assertOk()->assertJsonPath('data.status', Repair::ACCEPTED)->assertJsonPath('data.mine', true);

        $this->assertSame('2026-11-23 09:59:30', $repair->fresh()->accepted_at->toDateTimeString());
        $this->assertSame([FieldEvent::TAKEN, $this->near->id], [$event->fresh()->status, $event->fresh()->taken_by]);
        Notification::assertSentTo($this->courierA->user, FieldEventAlert::class, fn ($n) => $n->kind === 'field_event_taken');

        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/depart")->assertOk()->assertJsonPath('data.status', Repair::EN_ROUTE);
        Notification::assertSentTo($this->courierA->user, FieldEventAlert::class, fn ($n) => $n->kind === 'repair_en_route');

        Sanctum::actingAs($this->courierA->user);
        $this->getJson("/api/v1/courier/field-events/{$event->id}")->assertOk()
            ->assertJsonPath('data.repair.status', Repair::EN_ROUTE)
            ->assertJsonPath('data.repair.mechanic.name', 'Moussa Mécano');
    }

    public function test_decline_and_timeout_pass_the_breakdown_on_then_the_team_is_alerted(): void
    {
        Notification::fake();
        $this->report();
        $repair = $this->repair();

        Sanctum::actingAs($this->near);
        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/decline")->assertOk();
        $this->assertSame([Repair::OFFERED, $this->far->mechanic->id], [$repair->fresh()->status, $repair->fresh()->mechanic_id]);

        // Yao ne répond pas : délai passé, personne d'autre, l'équipe de l'atelier est prévenue
        $this->travel(6)->minutes();
        $this->artisan('repairs:dispatch')->assertSuccessful();
        $repair->refresh();
        $this->assertSame([Repair::SEARCHING, null], [$repair->status, $repair->mechanic_id]);
        $this->assertEqualsCanonicalizing([$this->near->mechanic->id, $this->far->mechanic->id], $repair->declined);
        Notification::assertSentTo($this->far, RepairAlert::class, fn ($n) => $n->kind === 'repair_expired');
        Notification::assertSentTo([$this->fleetManager, $this->dispatcher, $this->admin], RepairAlert::class, fn ($n) => $n->kind === 'repair_unassigned');
        Notification::assertNotSentTo($this->courierA->user, RepairAlert::class);

        // Une seule alerte à l'équipe, même si la panne reste sans mécanicien
        $this->artisan('repairs:dispatch')->assertSuccessful();
        Notification::assertSentToTimes($this->fleetManager, RepairAlert::class, 1);

        // Moussa change d'avis : il la prend lui-même depuis la liste
        Sanctum::actingAs($this->near);
        $this->assertSame([$repair->id], array_column($this->getJson('/api/v1/mechanic/repairs')->json('data.todo'), 'id'));
        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/accept")->assertOk()->assertJsonPath('data.status', Repair::ACCEPTED);
        Sanctum::actingAs($this->far);
        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/accept")->assertNotFound();
    }

    public function test_breakdown_waits_for_a_mechanic_who_goes_on_duty(): void
    {
        Notification::fake();
        Mechanic::query()->update(['is_available' => false]);
        $this->report();
        $repair = $this->repair();
        $this->assertSame(Repair::SEARCHING, $repair->status);
        Notification::assertSentTo($this->fleetManager, RepairAlert::class, fn ($n) => $n->kind === 'repair_unassigned' && str_contains($n->body, 'Aucun mécanicien en service'));

        Sanctum::actingAs($this->far);
        $this->patchJson('/api/v1/mechanic/status', ['is_available' => true, 'lat' => 5.4, 'lng' => -4.1])->assertOk()->assertJsonPath('data.is_available', true);
        $this->assertSame([Repair::OFFERED, $this->far->mechanic->id], [$repair->fresh()->status, $repair->fresh()->mechanic_id]);

        // Hors service : la proposition passe au suivant (personne ici)
        $this->patchJson('/api/v1/mechanic/status', ['is_available' => false])->assertOk();
        $this->assertSame(Repair::SEARCHING, $repair->fresh()->status);
    }

    public function test_full_intervention_updates_the_vehicle_log_and_puts_the_courier_back_on_duty(): void
    {
        Notification::fake();
        $event = $this->report();
        $repair = $this->repair();
        $this->assertSame('broken', $this->moto->fresh()->status);
        $this->assertFalse($this->courierA->fresh()->is_available);

        Sanctum::actingAs($this->near);
        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/accept")->assertOk();
        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/start", ['category' => 'electric', 'decision' => 'fixed'])
            ->assertUnprocessable()->assertJsonPath('message', 'Cette étape est déjà passée.');
        // Arrivée notée hors connexion à 10:17, envoyée à 10:20 : l'heure du clic est gardée
        $this->travel(20)->minutes();
        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/arrive", ['lat' => 5.3501, 'lng' => -4.0001, 'at' => '2026-11-23T10:17:00+00:00'])
            ->assertOk()->assertJsonPath('data.status', Repair::ON_SITE);
        $this->assertSame('2026-11-23 10:17:00', $repair->fresh()->arrived_at->toDateTimeString());

        $this->post("/api/v1/mechanic/repairs/{$repair->id}/photos", ['kind' => 'before', 'photos' => [UploadedFile::fake()->image('avant.jpg')]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonCount(1, 'data.files');
        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/close", ['cause' => 'wear'])
            ->assertUnprocessable()->assertJsonPath('message', 'Faites d\'abord le diagnostic.');
        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/start", ['category' => 'electric', 'part' => 'Cosse batterie', 'decision' => 'fixed'])
            ->assertOk()->assertJsonPath('data.status', Repair::REPAIRING)->assertJsonPath('data.category_label', 'Électrique');

        $this->travel(30)->minutes();
        $this->post("/api/v1/mechanic/repairs/{$repair->id}/photos", ['kind' => 'after', 'photos' => [UploadedFile::fake()->image('apres.jpg')]], ['Accept' => 'application/json'])->assertOk();
        $closed = $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/close", [
            'parts' => [['label' => 'Cosse de batterie', 'quantity' => 2, 'unit_price' => 750], ['label' => 'Fusible 15 A', 'unit_price' => 250], ['label' => '']],
            'cause' => 'courier_fault', 'odometer' => 18432, 'remark' => 'Batterie à changer sous un mois', 'courier_can_resume' => true,
        ])->assertOk()->json('data');

        $this->assertSame([Repair::DONE, 1750, 'Négligence du livreur', 2], [$closed['status'], $closed['total'], $closed['cause_label'], count($closed['parts'])]);
        $log = VehicleLog::where('vehicle_id', $this->moto->id)->sole();
        $this->assertSame(['repair', 1750, 18432, $event->id, 1], [$log->kind, $log->amount, $log->odometer, $log->field_event_id, count($log->photos)]);
        $this->assertStringContainsString('Réparation électrique : Cosse batterie', $log->label);
        $this->assertSame($log->id, $repair->fresh()->vehicle_log_id);
        $this->assertSame(['active', 18432], [$this->moto->fresh()->status, $this->moto->fresh()->odometer]);
        $this->assertSame(FieldEvent::RESOLVED, $event->fresh()->status);
        $this->assertTrue($this->courierA->fresh()->is_available);
        Notification::assertSentTo($this->courierA->user, FieldEventAlert::class, fn ($n) => $n->kind === 'field_event_resolved' && str_contains($n->body, 'Vous pouvez repartir'));

        // L'équipe voit l'intervention et ses délais
        Sanctum::actingAs($this->fleetManager);
        $atelier = $this->getJson('/api/v1/repairs?tab=done')->assertOk()->json();
        $this->assertSame([$repair->id], array_column($atelier['data'], 'id'));
        $this->assertSame([0, 17, 50], [$atelier['averages']['response'], $atelier['averages']['arrival'], $atelier['averages']['downtime']]);
        $file = $repair->files()->where('kind', 'after')->sole();
        $this->get("/api/v1/repairs/{$repair->id}/files/{$file->id}")->assertOk();
    }

    public function test_towing_keeps_the_courier_stopped_and_warns_the_team(): void
    {
        Notification::fake();
        $event = $this->report();
        $repair = $this->repair();
        Sanctum::actingAs($this->near);
        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/accept")->assertOk();
        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/arrive")->assertOk();
        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/start", ['category' => 'engine', 'part' => 'Courroie', 'decision' => 'towed'])->assertOk();
        Notification::assertSentTo($this->fleetManager, RepairAlert::class, fn ($n) => $n->kind === 'repair_decision');

        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/close", [
            'cause' => 'wear', 'garage' => 'Garage du Plateau', 'garage_amount' => 45000, 'courier_can_resume' => true,
        ])->assertOk()->assertJsonPath('data.courier_can_resume', false)->assertJsonPath('data.total', 45000);

        $this->assertSame('broken', $this->moto->fresh()->status);
        $this->assertSame(FieldEvent::TAKEN, $event->fresh()->status);
        $this->assertSame(['Garage du Plateau', 45000], [VehicleLog::sole()->garage, VehicleLog::sole()->amount]);
        Notification::assertSentTo($this->fleetManager, RepairAlert::class, fn ($n) => $n->kind === 'repair_done_blocked');
    }

    public function test_on_site_purchase_needs_the_admin_agreement(): void
    {
        Notification::fake();
        $this->report();
        $repair = $this->repair();
        Sanctum::actingAs($this->near);
        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/accept")->assertOk();
        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/arrive")->assertOk();
        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/start", ['category' => 'electric', 'decision' => 'fixed'])->assertOk();
        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/purchases", ['label' => 'Batterie 12 V', 'estimated_amount' => 13500, 'reason' => 'Magasin fermé'])
            ->assertOk()->assertJsonPath('data.purchases.0.status', RepairPurchase::PENDING);
        $purchase = RepairPurchase::sole();
        Notification::assertSentTo($this->admin, RepairAlert::class, fn ($n) => $n->kind === 'repair_purchase');
        Notification::assertNotSentTo($this->fleetManager, RepairAlert::class, fn ($n) => $n->kind === 'repair_purchase');

        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/close", ['cause' => 'wear', 'courier_can_resume' => true])
            ->assertUnprocessable()->assertJsonPath('message', 'Une demande d\'achat attend encore la réponse de l\'administrateur.');

        // Le responsable du parc n'accorde pas les achats : c'est l'administrateur
        Sanctum::actingAs($this->fleetManager);
        $this->postJson("/api/v1/repair-purchases/{$purchase->id}/decide", ['approve' => true])->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->assertSame([$purchase->id], array_column($this->getJson('/api/v1/repair-purchases')->assertOk()->json('data'), 'id'));
        $this->postJson("/api/v1/repair-purchases/{$purchase->id}/decide", ['approve' => true, 'max_amount' => 15000])->assertOk();
        Notification::assertSentTo($this->near, RepairAlert::class, fn ($n) => $n->kind === 'repair_purchase_decided' && str_contains($n->title, 'accordé'));

        Sanctum::actingAs($this->near);
        $this->post("/api/v1/mechanic/repairs/{$repair->id}/close", ['cause' => 'wear', 'purchases' => [$purchase->id => 16000]], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors("purchases.{$purchase->id}");
        $this->post("/api/v1/mechanic/repairs/{$repair->id}/close", [
            'cause' => 'wear', 'courier_can_resume' => '1',
            'purchases' => [$purchase->id => 14000], 'receipts' => [$purchase->id => UploadedFile::fake()->image('facture.jpg')],
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.total', 14000)->assertJsonPath('data.purchases.0.has_receipt', true);
        $this->assertSame(14000, $purchase->fresh()->paid_amount);
    }

    public function test_courier_resuming_before_arrival_cancels_the_intervention(): void
    {
        Notification::fake();
        $event = $this->report();
        $repair = $this->repair();
        Sanctum::actingAs($this->near);
        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/accept")->assertOk();

        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/courier/field-events/{$event->id}/resume")->assertOk();

        $this->assertSame(Repair::CANCELLED, $repair->fresh()->status);
        Notification::assertSentTo($this->near, RepairAlert::class, fn ($n) => $n->kind === 'repair_cancelled');
        Sanctum::actingAs($this->near);
        $this->postJson("/api/v1/mechanic/repairs/{$repair->id}/depart")->assertUnprocessable()->assertJsonPath('message', 'Cette intervention a été annulée.');
    }

    public function test_team_assigns_by_hand_and_creates_interventions(): void
    {
        Notification::fake();
        $this->report();
        $repair = $this->repair();

        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/repairs')->assertForbidden();

        Sanctum::actingAs($this->dispatcher);
        $mechanics = $this->getJson("/api/v1/repairs/mechanics?repair={$repair->id}")->assertOk()->json('data');
        $this->assertSame(['Moussa Mécano', 'Yao Mécano'], array_column($mechanics, 'name'));
        $this->postJson("/api/v1/repairs/{$repair->id}/assign", ['mechanic_id' => $this->far->mechanic->id])
            ->assertOk()->assertJsonPath('data.status', Repair::ACCEPTED)->assertJsonPath('data.mechanic.name', 'Yao Mécano');
        Notification::assertSentTo($this->near, RepairAlert::class, fn ($n) => $n->kind === 'repair_reassigned');
        Notification::assertSentTo($this->far, RepairAlert::class, fn ($n) => $n->kind === 'repair_assigned');

        // Intervention créée par l'équipe pour un problème vu au dépôt : proposée au plus proche libre
        Sanctum::actingAs($this->fleetManager);
        $created = $this->postJson('/api/v1/repairs', ['vehicle_id' => $this->moto->id, 'title' => 'Frein avant mou', 'severity' => 'hindrance'])
            ->assertCreated()->json('data');
        $this->assertSame([Repair::OFFERED, 'Moussa Mécano', 'manual'], [$created['status'], $created['mechanic']['name'], $created['source']]);
        $this->postJson("/api/v1/repairs/{$created['id']}/cancel", ['reason' => 'Réglé au dépôt'])->assertOk()->assertJsonPath('data.status', Repair::CANCELLED);
        Notification::assertSentTo($this->near, RepairAlert::class, fn ($n) => $n->kind === 'repair_cancelled' && $n->body === 'Réglé au dépôt');

        $this->assertSame(2, $this->getJson('/api/v1/repairs?tab=all')->assertOk()->json('meta.total'));
    }

    public function test_mechanic_space_roles_and_login(): void
    {
        $this->assertSame('mechanic', $this->near->space());
        $this->assertNotNull($this->near->mechanic);
        $this->assertFalse($this->near->isStaff());
        $this->assertTrue($this->fleetManager->isStaff());
        $this->assertTrue($this->fleetManager->can('repairs.manage'));
        $this->assertFalse($this->fleetManager->can('repairs.purchases'));
        $this->assertTrue($this->admin->can('repairs.purchases'));

        $this->near->update(['password' => 'secret-123']);
        $this->postJson('/api/v1/auth/login', ['login' => $this->near->phone, 'password' => 'secret-123', 'space' => 'courier'])
            ->assertUnprocessable()->assertJsonPath('space', 'mechanic');
        $this->postJson('/api/v1/auth/login', ['login' => $this->near->phone, 'password' => 'secret-123', 'space' => 'mechanic'])
            ->assertOk()->assertJsonPath('user.role', 'mechanic')->assertJsonPath('user.mechanic.is_available', true);

        // Les autres comptes n'entrent pas dans l'espace mécanicien
        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/mechanic/repairs')->assertForbidden();
        Sanctum::actingAs($this->near);
        $this->getJson('/api/v1/repairs')->assertForbidden();
        $this->getJson('/api/v1/mechanic/setup')->assertOk()->assertJsonPath('data.categories.1.label', 'Électrique');
        $this->getJson("/api/v1/mechanic/vehicles/{$this->moto->id}")->assertOk()->assertJsonPath('data.plate', 'AB-4521-CI');
        $this->assertSame(['AB-4521-CI'], array_column($this->getJson('/api/v1/mechanic/vehicles?q=4521')->json('data'), 'plate'));
    }

    public function test_no_intervention_without_mechanics_or_for_other_event_types(): void
    {
        Notification::fake();
        $this->report(['type' => 'control', 'capacity' => 'continue']);
        $this->assertSame(0, Repair::withoutGlobalScopes()->count());

        Mechanic::query()->delete();
        $this->report();
        $this->assertSame(0, Repair::withoutGlobalScopes()->count());
        $this->assertSame(0, app(RepairDispatcher::class)->tick()['offered']);
    }
}
