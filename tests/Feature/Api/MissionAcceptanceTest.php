<?php

namespace Tests\Feature\Api;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierRule;
use App\Models\Order;
use App\Notifications\OrderAlert;
use App\Services\Orders\OrderDispatcher;
use App\Services\Orders\OrderWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Acceptation des missions (le livreur accepte ou refuse, d'office avec empêchement possible, d'office),
 * exception par livreur, remplaçant après un refus ou un livreur injoignable, alerte des ramassages pas commencés
 * avant l'heure limite, suspension de l'automatisation.
 */
class MissionAcceptanceTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private Courier $moussa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo(CarbonImmutable::parse('2026-11-23 09:00', 'UTC'));
        $this->company->update(['timezone' => 'UTC']);
        $this->moussa = $this->userWithRole(Role::Courier, ['name' => 'Moussa Remplaçant'])->courier;
        foreach ([$this->courierA, $this->courierB, $this->moussa] as $courier) {
            $courier->forceFill(['is_available' => true, 'last_seen_at' => now()])->save();
        }
    }

    private function confirmed(): Order
    {
        $order = $this->createOrder();
        app(OrderWorkflow::class)->transition($this->dispatcher, $order, OrderStatus::Confirmed);

        return $order->fresh();
    }

    private function give(Order $order, Courier $courier)
    {
        return app(OrderDispatcher::class)->assign($this->dispatcher, $order, AssignmentType::Pickup, $courier);
    }

    private function alerts(string $kind): int
    {
        return Notification::sent($this->dispatcher, OrderAlert::class)->filter(fn (OrderAlert $n) => $n->kind === $kind)->count();
    }

    private function planCocody(): void
    {
        CourierRule::create(['company_id' => $this->company->id, 'type' => 'pickup', 'courier_id' => $this->courierB->id,
            'backup_courier_id' => $this->moussa->id, 'zone_ids' => [$this->cocody->id]]);
        $this->company->update(['auto_pickup' => Company::AUTO_ON]);
    }

    public function test_the_three_acceptance_rules_and_the_courier_exception(): void
    {
        // Le livreur accepte ou refuse (par défaut)
        $this->assertSame(AssignmentStatus::Assigned, $this->give($this->confirmed(), $this->courierA)->status);

        // D'office, empêchement possible : la mission rendue revient à l'équipe
        $this->company->update(['mission_acceptance' => Company::ACCEPT_REPORT]);
        $order = $this->confirmed();
        $mission = $this->give($order, $this->courierA);
        $this->assertSame(AssignmentStatus::Accepted, $mission->status);
        Sanctum::actingAs($this->courierA->user);
        $this->assertTrue(collect($this->getJson('/api/v1/courier/missions')->json('data'))->firstWhere('id', $mission->id)['can_report_impediment']);
        $this->postJson("/api/v1/courier/assignments/{$mission->id}/refuse", ['reason' => 'Moto en panne'])->assertOk();
        $this->assertSame([OrderStatus::Confirmed, null], [$order->fresh()->status, $order->fresh()->pickup_courier_id]);
        $this->assertSame(['impediment' => true], array_intersect_key($order->events()->where('type', 'assignment_refused')->sole()->meta, ['impediment' => 1]));

        // D'office sans refus possible
        $this->company->update(['mission_acceptance' => Company::ACCEPT_AUTO]);
        $mission = $this->give($this->confirmed(), $this->courierA);
        $this->postJson("/api/v1/courier/assignments/{$mission->id}/refuse", ['reason' => 'Non'])->assertStatus(422);
        $this->assertFalse(collect($this->getJson('/api/v1/courier/missions')->json('data'))->firstWhere('id', $mission->id)['can_report_impediment']);

        // Exception pour un livreur : il choisit, même si l'entreprise confie d'office
        $this->courierB->update(['mission_acceptance' => Company::ACCEPT_CHOOSE]);
        $this->assertSame(AssignmentStatus::Assigned, $this->give($this->confirmed(), $this->courierB)->status);

        // Réglage par l'administrateur seulement
        Sanctum::actingAs($this->dispatcher);
        $this->patchJson("/api/v1/couriers/{$this->courierA->id}", ['mission_acceptance' => 'choose'])->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/couriers/{$this->courierA->id}", ['mission_acceptance' => 'choose'])->assertOk()->assertJsonPath('data.mission_acceptance', 'choose');
    }

    public function test_a_planning_pickup_is_accepted_and_goes_to_the_backup_after_a_refusal(): void
    {
        $this->planCocody();
        $order = $this->confirmed();
        $first = $order->assignments()->sole();
        $this->assertSame([AssignmentStatus::Accepted, $this->courierB->id], [$first->status, $first->courier_id]);

        // Awa rend la mission : Moussa (remplaçant) la reçoit
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/courier/assignments/{$first->id}/refuse", ['reason' => 'Malade'])->assertOk();
        $order->refresh();
        $this->assertSame([OrderStatus::PickupAssigned, $this->moussa->id], [$order->status, $order->pickup_courier_id]);
        $this->assertStringContainsString('Moussa Remplaçant (remplaçant), planning « Cocody · tous les jours » (empêchement de Awa Livreuse)', $order->events()->where('type', 'note')->latest('id')->first()->note);

        // Moussa aussi : la course revient à l'équipe, et le rattrapage ne la redonne à aucun des deux
        Sanctum::actingAs($this->moussa->user);
        $this->postJson('/api/v1/courier/assignments/'.$order->activeAssignment(AssignmentType::Pickup)->id.'/refuse', ['reason' => 'Trop loin'])->assertOk();
        $this->artisan('assignments:auto')->assertSuccessful();
        $this->assertSame([OrderStatus::Confirmed, null], [$order->fresh()->status, $order->fresh()->pickup_courier_id]);
    }

    public function test_an_unreachable_courier_hands_planning_pickups_to_the_backup_and_the_team_is_told(): void
    {
        Notification::fake();
        $this->planCocody();
        $planned = $this->confirmed();
        $manual = $this->give($this->confirmed(), $this->courierB);

        // Signe de vie récent : rien
        $this->travel(25)->minutes();
        $this->courierB->forceFill(['last_seen_at' => now()->subMinutes(5)])->save();
        $this->artisan('assignments:watch')->assertSuccessful();
        $this->assertSame(0, $this->alerts('courier_unreachable'));

        // Plus rien depuis 25 minutes : le ramassage du planning passe à Moussa, l'autre reste, l'équipe est prévenue
        $this->courierB->forceFill(['last_seen_at' => now()->subMinutes(25), 'last_location_at' => null])->save();
        $this->artisan('assignments:watch')->assertSuccessful();
        $this->assertSame($this->moussa->id, $planned->fresh()->pickup_courier_id);
        $this->assertSame($this->courierB->id, $manual->order->fresh()->pickup_courier_id);
        Notification::assertSentTo($this->dispatcher, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'courier_unreachable'
            && str_contains($n->title, 'Awa Livreuse injoignable') && str_contains($n->body, 'Confiées à un autre livreur : Moussa Remplaçant (1)'));

        // Une seule alerte
        $this->artisan('assignments:watch')->assertSuccessful();
        $this->assertSame(1, $this->alerts('courier_unreachable'));
    }

    public function test_pickups_not_started_before_the_cutoff_are_reported_once_without_being_withdrawn(): void
    {
        Notification::fake();
        $this->company->update(['daily_cutoff_time' => '15:00', 'late_pickup_alert_minutes' => 60, 'unreachable_minutes' => 0]);
        $order = $this->confirmed();
        $this->give($order, $this->courierA);

        $this->travelTo(CarbonImmutable::parse('2026-11-23 13:30', 'UTC'));
        $this->artisan('assignments:watch')->assertSuccessful();
        $this->assertSame(0, $this->alerts('late_pickup'));

        $this->travelTo(CarbonImmutable::parse('2026-11-23 14:05', 'UTC'));
        $this->artisan('assignments:watch')->assertSuccessful();
        $this->artisan('assignments:watch')->assertSuccessful();
        $this->assertSame(1, $this->alerts('late_pickup'));
        Notification::assertSentTo($this->dispatcher, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'late_pickup'
            && str_contains($n->title, '1 ramassage pas commencé (heure limite 15:00)') && str_contains($n->body, 'Koffi Ramasseur : 1'));
        $this->assertSame($this->courierA->id, $order->fresh()->pickup_courier_id);
    }

    public function test_the_automation_can_be_suspended(): void
    {
        $this->planCocody();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson('/api/v1/planning/pause', ['paused' => true])->assertOk()->assertJsonPath('data.paused', true)
            ->assertJsonPath('data.paused_by', $this->dispatcher->name);

        $order = $this->confirmed();
        $this->artisan('assignments:auto')->assertSuccessful();
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);

        $this->postJson('/api/v1/planning/pause', ['paused' => false])->assertOk();
        $this->getJson('/api/v1/planning')->assertJsonPath('data.automation.paused', false);
        $this->artisan('assignments:auto')->assertSuccessful();
        $this->assertSame($this->courierB->id, $order->fresh()->pickup_courier_id);
    }
}
