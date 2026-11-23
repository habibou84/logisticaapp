<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\BillingPlan;
use App\Models\CourierRoutine;
use App\Models\CourierShift;
use App\Models\CourierShiftAttempt;
use App\Models\Destination;
use App\Models\Merchant;
use App\Models\User;
use App\Notifications\ShiftMissingAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Pointage des livreurs à leur lieu de début, seulement sur place : l'agence (distribution, e-commerce), le siège du
 * client en MAD, ou la première étape du parcours défini par l'équipe ou par le client. Le pointage met en service.
 */
class CourierShiftTest extends TestCase
{
    use BuildsDeliveryWorld;
    use RefreshDatabase;

    private const MONDAY = '2026-11-23';

    // Agence (Cocody) et siège du client MAD (Plateau)
    private const AGENCY = [5.3600000, -3.9900000];

    private const HQ = [5.3200000, -4.0200000];

    // Agence du client à Yopougon (première étape du parcours)
    private const BRANCH = [5.3400000, -4.0800000];

    private Merchant $madClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->company->update(['shift_settings' => [
            'agency_name' => 'Agence Cocody', 'agency_lat' => self::AGENCY[0], 'agency_lng' => self::AGENCY[1], 'agency_start' => '08:00',
        ]]);
        $this->madClient = Merchant::factory()->create([
            'company_id' => $this->company->id, 'business_name' => 'Mamadou SA', 'pickup_zone_id' => $this->plateau->id,
            'pickup_lat' => self::HQ[0], 'pickup_lng' => self::HQ[1],
        ]);
        $this->madClient->syncActivities(['mad']);
    }

    /** Point à environ $meters mètres au nord */
    private static function north(array $point, int $meters): array
    {
        return [$point[0] + $meters / 111_320, $point[1]];
    }

    private function clockIn(User $user, array $point, ?int $accuracy = 10)
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/courier/shift', ['lat' => round($point[0], 7), 'lng' => round($point[1], 7), 'accuracy' => $accuracy]);
    }

    private function madPlan(array $options = ['mad_from' => '07:30', 'mad_to' => '17:00']): BillingPlan
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson("/api/v1/merchants/{$this->madClient->id}/billing-plans", [
            'formula' => 'mad_contract', 'monthly_fee' => 150000, 'included_quantity' => 0, 'overage_price' => 0,
            'delivery_delay' => '48h', 'starts_on' => '2026-11-01', 'courier_ids' => [$this->courierB->id], 'options' => $options,
        ])->assertCreated()->json('data.id');

        return BillingPlan::findOrFail($id);
    }

    private function routinePayload(array $overrides = []): array
    {
        return [
            'name' => 'Tournée des agences',
            'days' => [1, 2, 3, 4, 5],
            'starts_at' => '07:00',
            'stops' => [
                ['place_type' => 'destination', 'new_place' => ['name' => 'Agence Mamadou Yopougon', 'address' => 'Siporex', 'lat' => self::BRANCH[0], 'lng' => self::BRANCH[1]], 'action' => 'pickup', 'instructions' => 'Demander Awa'],
                ['place_type' => 'merchant', 'action' => 'drop'],
            ],
            ...$overrides,
        ];
    }

    public function test_distribution_courier_clocks_in_at_the_agency_only_on_site(): void
    {
        $this->travelTo(self::MONDAY.' 07:50:00');
        $this->courierA->update(['is_available' => false]);

        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/courier/shift')->assertOk()
            ->assertJsonPath('data.place.kind', 'agency')
            ->assertJsonPath('data.place.label', 'Agence Cocody')
            ->assertJsonPath('data.place.radius', 50)
            ->assertJsonPath('data.shift', null);

        // Trop loin (1 km), position imprécise : refusés et gardés pour le contrôle
        $this->clockIn($this->courierA->user, self::north(self::AGENCY, 1000))->assertStatus(422)->assertJsonValidationErrors('distance');
        $this->clockIn($this->courierA->user, self::north(self::AGENCY, 20), 80)->assertStatus(422)->assertJsonValidationErrors('accuracy');
        $this->assertSame(['too_far', 'inaccurate'], CourierShiftAttempt::orderBy('id')->pluck('reason')->all());

        // 70 m avec un GPS à ± 25 m : 45 m une fois l'imprécision retirée, accepté
        $this->clockIn($this->courierA->user, self::north(self::AGENCY, 70), 25)->assertCreated()
            ->assertJsonPath('data.shift.place_label', 'Agence Cocody')
            ->assertJsonPath('data.shift.late_minutes', 0)
            ->assertJsonPath('data.is_available', true);
        $this->assertTrue($this->courierA->fresh()->is_available, 'le pointage met en service');

        // Une fois par jour
        $this->clockIn($this->courierA->user, self::AGENCY)->assertStatus(422)->assertJsonValidationErrors('shift');
        $this->assertSame(1, CourierShift::count());
    }

    public function test_too_early_is_refused_and_lateness_is_noted(): void
    {
        $this->travelTo(self::MONDAY.' 07:00:00');
        $this->clockIn($this->courierA->user, self::AGENCY)->assertStatus(422)->assertJsonFragment(['message' => 'Trop tôt : le pointage ouvre à 07:30 (début prévu à 08:00).']);

        $this->travelTo(self::MONDAY.' 08:20:00');
        $this->clockIn($this->courierA->user, self::AGENCY)->assertCreated()->assertJsonPath('data.shift.late_minutes', 20);
    }

    public function test_agency_without_position_cannot_be_clocked_by_gps(): void
    {
        $this->company->update(['shift_settings' => ['agency_start' => '08:00']]);
        $this->travelTo(self::MONDAY.' 08:00:00');

        $this->clockIn($this->courierA->user, self::AGENCY)->assertStatus(422)->assertJsonValidationErrors('shift');
    }

    public function test_mad_courier_without_routine_clocks_in_at_the_client(): void
    {
        $this->madPlan();
        $this->travelTo(self::MONDAY.' 07:25:00');

        Sanctum::actingAs($this->courierB->user);
        $this->getJson('/api/v1/courier/shift')->assertOk()
            ->assertJsonPath('data.place.kind', 'mad')
            ->assertJsonPath('data.place.label', 'Siège · Mamadou SA');

        $this->clockIn($this->courierB->user, self::AGENCY)->assertStatus(422)->assertJsonValidationErrors('distance');
        $this->clockIn($this->courierB->user, self::HQ)->assertCreated()->assertJsonPath('data.shift.place_label', 'Siège · Mamadou SA');

        // Le client voit l'heure d'arrivée de son livreur
        Sanctum::actingAs(User::factory()->withRole(Role::MerchantOwner)->create(['company_id' => $this->company->id, 'merchant_id' => $this->madClient->id]));
        $courier = collect($this->getJson('/api/v1/merchant/space')->assertOk()->json('data.couriers'))->firstWhere('id', $this->courierB->id);
        $this->assertNotNull($courier['clocked_in_at']);
        $this->assertSame('Siège · Mamadou SA', $courier['clocked_in_place']);
    }

    public function test_client_routine_moves_the_clock_in_place_to_its_first_stop(): void
    {
        $plan = $this->madPlan();
        $owner = User::factory()->withRole(Role::MerchantOwner)->create(['company_id' => $this->company->id, 'merchant_id' => $this->madClient->id]);

        // Le client écrit le parcours : agence de Yopougon (nouveau lieu ajouté à l'annuaire), puis le siège
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/merchant/routines')->assertOk()->assertJsonPath('plan.id', $plan->id)->assertJsonPath('can_edit', true);
        $routine = $this->postJson('/api/v1/merchant/routines', $this->routinePayload())->assertCreated()
            ->assertJsonPath('data.source', 'merchant')
            ->assertJsonPath('data.first_located', true)
            ->assertJsonPath('data.stops.0.label', 'Agence Mamadou Yopougon')
            ->assertJsonPath('data.stops.1.label', 'Siège · Mamadou SA')
            ->json('data');
        $this->assertSame('routine', Destination::where('name', 'Agence Mamadou Yopougon')->value('source'));

        // Le livreur pointe à la première étape, à l'heure du parcours
        $this->travelTo(self::MONDAY.' 06:45:00');
        Sanctum::actingAs($this->courierB->user);
        $this->getJson('/api/v1/courier/shift')->assertOk()
            ->assertJsonPath('data.place.label', 'Agence Mamadou Yopougon')
            ->assertJsonPath('data.place.routine', 'Tournée des agences')
            ->assertJsonCount(2, 'data.place.stops');
        $this->clockIn($this->courierB->user, self::HQ)->assertStatus(422)->assertJsonValidationErrors('distance');
        $this->clockIn($this->courierB->user, self::north(self::BRANCH, 15))->assertCreated()
            ->assertJsonPath('data.shift.place_label', 'Agence Mamadou Yopougon');
        $this->assertSame($routine['id'], CourierShift::value('courier_routine_id'));

        // Changement du client : il vaut tout de suite (le lendemain, le siège redevient le lieu de pointage)
        Sanctum::actingAs($owner);
        $this->putJson("/api/v1/merchant/routines/{$routine['id']}", $this->routinePayload(['is_active' => false]))->assertOk();
        $this->travelTo('2026-11-24 07:20:00');
        Sanctum::actingAs($this->courierB->user);
        $this->getJson('/api/v1/courier/shift')->assertOk()->assertJsonPath('data.place.label', 'Siège · Mamadou SA');
    }

    public function test_the_team_sees_every_mad_contract_on_the_routines_page(): void
    {
        $plan = $this->madPlan();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/billing-plans/{$plan->id}/routines", $this->routinePayload())->assertCreated();

        $this->getJson('/api/v1/routines/contracts')->assertOk()
            ->assertJsonPath('data.0.id', $plan->id)
            ->assertJsonPath('data.0.merchant', $this->madClient->business_name)
            ->assertJsonPath('data.0.routines_count', 1)
            ->assertJsonPath('can_edit', true);

        // Ni le client ni le livreur
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/routines/contracts')->assertForbidden();
        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/routines/contracts')->assertForbidden();
    }

    public function test_routine_only_on_its_days_and_for_its_courier(): void
    {
        $plan = $this->madPlan();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/billing-plans/{$plan->id}/routines", $this->routinePayload(['days' => [2], 'courier_id' => $this->courierB->id]))
            ->assertCreated()->assertJsonPath('data.source', 'staff');
        // Livreur hors contrat : refusé
        $this->postJson("/api/v1/billing-plans/{$plan->id}/routines", $this->routinePayload(['courier_id' => $this->courierA->id]))
            ->assertStatus(422)->assertJsonValidationErrors('courier_id');

        $this->travelTo(self::MONDAY.' 07:00:00');
        Sanctum::actingAs($this->courierB->user);
        $this->getJson('/api/v1/courier/shift')->assertJsonPath('data.place.label', 'Siège · Mamadou SA');
        $this->travelTo('2026-11-24 07:00:00');
        $this->getJson('/api/v1/courier/shift')->assertJsonPath('data.place.label', 'Agence Mamadou Yopougon');
    }

    public function test_client_rights_on_routines(): void
    {
        $plan = $this->madPlan();
        $viewer = User::factory()->withRole(Role::MerchantViewer)->create(['company_id' => $this->company->id, 'merchant_id' => $this->madClient->id]);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/merchant/routines')->assertOk()->assertJsonPath('can_edit', false);
        $this->postJson('/api/v1/merchant/routines', $this->routinePayload())->assertForbidden();

        // Parcours d'un autre client : introuvable
        Sanctum::actingAs($this->admin);
        $id = $this->postJson("/api/v1/billing-plans/{$plan->id}/routines", $this->routinePayload())->assertCreated()->json('data.id');
        Sanctum::actingAs($this->merchantUser);
        $this->putJson("/api/v1/merchant/routines/{$id}", $this->routinePayload())->assertStatus(422);
        $this->deleteJson("/api/v1/merchant/routines/{$id}")->assertStatus(422);
        $this->assertTrue(CourierRoutine::whereKey($id)->exists());

        // Livreur : pas d'accès aux parcours de l'équipe
        Sanctum::actingAs($this->courierB->user);
        $this->getJson("/api/v1/billing-plans/{$plan->id}/routines")->assertForbidden();
    }

    public function test_manual_clock_in_with_reason_and_the_day_board(): void
    {
        $this->madPlan();
        $this->travelTo(self::MONDAY.' 08:30:00');

        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/couriers/{$this->courierB->id}/shift", ['reason' => 'GPS en panne'])->assertForbidden();

        Sanctum::actingAs($this->dispatcher);
        $board = $this->getJson('/api/v1/shifts')->assertOk()->json('data');
        $rows = collect($board['rows'])->keyBy('courier_id');
        $this->assertSame('missing', $rows[$this->courierB->id]['state']);
        $this->assertSame(60, $rows[$this->courierB->id]['late_now']);
        $this->assertSame('missing', $rows[$this->courierA->id]['state']);

        $this->postJson("/api/v1/couriers/{$this->courierB->id}/shift", ['time' => '07:40'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/couriers/{$this->courierB->id}/shift", ['time' => '07:40', 'reason' => 'GPS en panne, vu sur place'])->assertCreated()
            ->assertJsonPath('data.method', 'manual')
            ->assertJsonPath('data.late_minutes', 10)
            ->assertJsonPath('data.recorded_by', $this->dispatcher->name);
        $this->assertTrue($this->courierB->fresh()->is_available);

        $row = collect($this->getJson('/api/v1/shifts')->json('data.rows'))->firstWhere('courier_id', $this->courierB->id);
        $this->assertSame(['late', 'manual'], [$row['state'], $row['shift']['method']]);

        // Annulé : le livreur peut pointer à nouveau
        $this->deleteJson("/api/v1/courier-shifts/{$row['shift']['id']}")->assertOk();
        $this->assertSame(0, CourierShift::count());
    }

    public function test_dispatch_is_alerted_once_when_a_courier_has_not_clocked_in(): void
    {
        Notification::fake();
        $this->travelTo(self::MONDAY.' 08:10:00');
        $this->artisan('shifts:missing')->assertSuccessful();
        Notification::assertNothingSent();

        $this->travelTo(self::MONDAY.' 08:20:00');
        $this->artisan('shifts:missing')->assertSuccessful();
        Notification::assertSentTo($this->dispatcher, ShiftMissingAlert::class, fn (ShiftMissingAlert $n) => count($n->rows) === 2);

        $this->travelTo(self::MONDAY.' 08:30:00');
        $this->artisan('shifts:missing')->assertSuccessful();
        Notification::assertSentToTimes($this->dispatcher, ShiftMissingAlert::class, 1);
    }

    public function test_absent_courier_and_non_working_day_have_no_clock_in(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/planning/absences', ['courier_id' => $this->courierA->id, 'starts_on' => self::MONDAY, 'ends_on' => self::MONDAY, 'reason' => 'Malade'])->assertCreated();
        $this->travelTo(self::MONDAY.' 08:00:00');
        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/courier/shift')->assertOk()->assertJsonPath('data.place', null);
        $this->clockIn($this->courierA->user, self::AGENCY)->assertStatus(422);

        // Dimanche : l'agence ne travaille pas (lundi au samedi par défaut)
        $this->travelTo('2026-11-29 08:00:00');
        Sanctum::actingAs($this->courierB->user);
        $this->getJson('/api/v1/courier/shift')->assertOk()->assertJsonPath('data.place', null);
    }

    public function test_settings_are_saved_and_cleaned(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/companies/{$this->company->id}", ['shift_settings' => [
            'agency_name' => 'Siège Logistica', 'agency_lat' => 5.35, 'agency_lng' => -4.0, 'radius' => 50, 'agency_days' => [1, 2, 3, 4, 5], 'unknown' => 'x',
        ]])->assertOk()
            ->assertJsonPath('data.shift_settings.agency_name', 'Siège Logistica')
            ->assertJsonPath('data.shift_settings.agency_days', [1, 2, 3, 4, 5])
            ->assertJsonPath('data.shift_settings.min_accuracy', 30);
        $this->assertArrayNotHasKey('unknown', $this->company->fresh()->shift_settings);
    }
}
