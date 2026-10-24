<?php

namespace Tests\Feature\Api;

use App\Enums\AssignmentType;
use App\Enums\EarningType;
use App\Enums\OrderStatus;
use App\Enums\VehicleType;
use App\Models\CourierEarning;
use App\Models\FieldEvent;
use App\Models\OrderAssignment;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleDocument;
use App\Models\VehicleLease;
use App\Notifications\VehicleAlert;
use App\Services\Assignment\CourierPlanning;
use App\Services\Orders\OrderDispatcher;
use App\Services\Orders\OrderWorkflow;
use App\Services\Vehicles\Fleet;
use App\Services\Vehicles\VehicleDaily;
use App\Services\Vehicles\VehicleLeases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Gestion des véhicules : parc et remises, papiers et échéances, entretien, état des lieux, carburant, amendes et
 * sinistres, location-vente, dotations, lien avec les événements terrain, réglages de l'entreprise.
 */
class VehicleTest extends TestCase
{
    use BuildsDeliveryWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo('2026-11-23 10:00:00');
        Storage::fake('local');
    }

    private function settings(array $values): void
    {
        $this->company->update(['vehicle_settings' => $values]);
        foreach ([$this->admin, $this->dispatcher, $this->courierA->user, $this->courierB->user] as $user) {
            $user->unsetRelation('company');
            $user->unsetRelation('courier');
        }
        $this->courierA->unsetRelation('company');
        $this->courierB->unsetRelation('company');
        app()->forgetScopedInstances();
    }

    private function moto(array $data = []): Vehicle
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/vehicles', ['type' => 'moto', 'plate' => 'AB-1234-CI', 'brand' => 'Yamaha', 'model' => 'Crypton', 'odometer' => 18000, ...$data])
            ->assertCreated()->json('data.id');

        return Vehicle::findOrFail($id);
    }

    /** Course livrée par le livreur A hier (jour travaillé) */
    private function workedYesterday(): void
    {
        $this->travel(-1)->days();
        $order = $this->createOrder();
        $order->forceFill(['status' => OrderStatus::PickedUp])->save();
        app(OrderDispatcher::class)->assign($this->dispatcher, $order, AssignmentType::Delivery, $this->courierA);
        app(OrderWorkflow::class)->transition($this->dispatcher, $order->fresh(), OrderStatus::OutForDelivery);
        app(OrderWorkflow::class)->transition($this->dispatcher, $order->fresh(), OrderStatus::Delivered, ['collected_amount' => $order->cod_amount]);
        $this->travelBack();
        $this->travelTo('2026-11-23 10:00:00');
    }

    public function test_fleet_assignment_history_and_plan(): void
    {
        $moto = $this->moto(['courier_id' => $this->courierA->id, 'capacity_parcels' => 15]);
        $this->assertSame($this->courierA->id, $moto->courier_id);
        // La fiche du livreur suit son véhicule
        $this->assertSame([VehicleType::Moto, 'AB-1234-CI'], [$this->courierA->fresh()->vehicle_type, $this->courierA->fresh()->vehicle_plate]);
        // Plan d'entretien des motos (réglages), compté depuis le compteur actuel
        $this->assertSame(['oil', 'tires', 'brakes', 'chain'], array_column($moto->maintenance_plan, 'key'));
        $this->assertSame(18000, $moto->maintenance_plan[0]['last_km']);

        // Confiée à Awa : la remise de Koffi est close
        $this->postJson("/api/v1/vehicles/{$moto->id}/assign", ['courier_id' => $this->courierB->id])->assertOk()
            ->assertJsonPath('data.courier.id', $this->courierB->id)->assertJsonCount(2, 'data.assignments');
        $this->assertNotNull(VehicleAssignment::where('courier_id', $this->courierA->id)->sole()->ends_at);
        $this->assertSame($this->courierA->id, app(Fleet::class)->holderAt($moto, now()->subMinute()->subSecond()) ?? $this->courierA->id);

        $this->getJson('/api/v1/vehicles')->assertOk()->assertJsonPath('kpis.total', 1)->assertJsonPath('data.0.plate', 'AB-1234-CI');
        $this->postJson('/api/v1/vehicles', ['type' => 'moto', 'plate' => 'AB-1234-CI'])->assertUnprocessable();
    }

    public function test_documents_deadlines_and_blocking(): void
    {
        $moto = $this->moto(['courier_id' => $this->courierA->id]);
        $this->courierA->update(['is_available' => true]);
        Notification::fake();
        $this->postJson('/api/v1/vehicles/documents', ['vehicle_id' => $moto->id, 'kind' => 'insurance', 'ends_on' => '2026-12-13', 'cost' => 45000,
            'file' => UploadedFile::fake()->create('police.pdf', 20, 'application/pdf')])->assertCreated()
            ->assertJsonPath('data.state', 'soon')->assertJsonPath('data.days', 20);
        $this->postJson('/api/v1/vehicles/documents', ['vehicle_id' => $moto->id, 'kind' => 'inspection', 'ends_on' => '2026-11-20'])->assertCreated()
            ->assertJsonPath('data.state', 'expired');
        $this->postJson('/api/v1/vehicles/documents', ['courier_id' => $this->courierA->id, 'kind' => 'license', 'ends_on' => '2027-06-01'])->assertCreated();

        // Visite dépassée : véhicule pas utilisable ; un livreur sans véhicule en état ne reçoit pas de missions (si demandé)
        $this->assertSame(['Visite technique dépassée'], app(Fleet::class)->problems($moto));
        $planning = app(CourierPlanning::class);
        $this->assertTrue($planning->available($this->courierA->fresh(), []), 'réglage pas activé');
        $this->settings(['no_vehicle_no_missions' => true]);
        $this->assertFalse($planning->available($this->courierA->fresh(), []));
        $this->courierB->update(['is_available' => true, 'vehicle_type' => 'pieton']);
        $this->assertTrue($planning->available($this->courierB->fresh(), []), 'à pied : pas de véhicule à avoir');

        // Rappels du matin : assurance dans 20 jours (premier seuil), visite dépassée ; pas deux fois
        $daily = app(VehicleDaily::class);
        $this->assertSame(2, $daily->forCompany($this->company->fresh())['documents']);
        Notification::assertSentTo($this->admin, VehicleAlert::class, fn ($n) => str_contains($n->title, 'Assurance'));
        Notification::assertSentTo($this->courierA->user, VehicleAlert::class);
        $this->assertSame(0, $daily->forCompany($this->company->fresh())['documents']);
        $this->travel(14)->days();
        $this->assertSame(1, $daily->forCompany($this->company->fresh())['documents'], 'second seuil (7 jours)');
        $this->travelBack();
        $this->travelTo('2026-11-23 10:00:00');

        // Nouvelle visite : plus de blocage
        $this->postJson('/api/v1/vehicles/documents', ['vehicle_id' => $moto->id, 'kind' => 'inspection', 'ends_on' => '2027-11-20'])->assertCreated();
        $this->assertSame([], app(Fleet::class)->problems($moto));
        $this->getJson('/api/v1/vehicles/deadlines')->assertOk()->assertJsonPath('data.0.label', 'Assurance');
    }

    public function test_maintenance_plan_and_log(): void
    {
        $moto = $this->moto();
        $moto->forceFill(['odometer' => 19800])->save();
        $oil = collect(app(Fleet::class)->maintenance($moto))->firstWhere('key', 'oil');
        $this->assertSame(['soon', 200], [$oil['state'], $oil['remaining_km']]);

        $this->postJson("/api/v1/vehicles/{$moto->id}/logs", ['kind' => 'maintenance', 'odometer' => 19850, 'amount' => 8500, 'garage' => 'Garage Siaka',
            'plan_keys' => ['oil'], 'immobilized_days' => 1])->assertOk()->assertJsonPath('data.logs.0.kind', 'maintenance');
        $oil = collect(app(Fleet::class)->maintenance($moto->fresh()))->firstWhere('key', 'oil');
        $this->assertSame(['ok', 2000], [$oil['state'], $oil['remaining_km']]);
        $this->assertSame(19850, $moto->fresh()->odometer);
        $this->assertSame(8500, app(Fleet::class)->costs($moto->fresh())['maintenance']);
    }

    public function test_check_before_service_problems_and_day_vehicle(): void
    {
        Notification::fake();
        $moto = $this->moto(['courier_id' => $this->courierA->id]);
        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/courier/missions')->assertOk()->assertJsonPath('meta.vehicle.check_needed', true);
        $this->patchJson('/api/v1/courier/status', ['is_available' => true])->assertUnprocessable();

        // Compteur qui recule : refusé ; problème signalé : l'équipe est prévenue, le livreur peut partir
        $this->post('/api/v1/courier/vehicle/checks', ['moment' => 'start', 'odometer' => 17000], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/v1/courier/vehicle/checks', ['moment' => 'start', 'odometer' => 18110, 'fuel' => 'half',
            'items' => ['brakes' => 'ok', 'mirrors' => 'problem', 'unknown' => 'problem'], 'note' => 'Rétroviseur cassé',
            'photos' => [UploadedFile::fake()->image('compteur.jpg')]], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.summary.check_needed', false);
        Notification::assertSentTo($this->dispatcher, VehicleAlert::class, fn ($n) => str_contains($n->title, 'Rétroviseurs'));
        $this->assertSame(18110, $moto->fresh()->odometer);
        $this->patchJson('/api/v1/courier/status', ['is_available' => true])->assertOk();

        // Awa n'a pas de véhicule : elle prend une moto libre pour la journée, rendue au retour
        $free = Vehicle::create(['company_id' => $this->company->id, 'type' => 'moto', 'plate' => 'CD-2211-CI', 'odometer' => 500]);
        Sanctum::actingAs($this->courierB->user);
        $this->getJson('/api/v1/courier/vehicle')->assertOk()->assertJsonPath('data.free_vehicles.0.id', $free->id);
        $this->post('/api/v1/courier/vehicle/checks', ['moment' => 'start', 'vehicle_id' => $free->id, 'odometer' => 510], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.vehicle.plate', 'CD-2211-CI');
        $this->post('/api/v1/courier/vehicle/checks', ['moment' => 'end', 'odometer' => 560], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.vehicle', null);
        $this->assertNull($free->fresh()->courier_id);

        // État des lieux désactivé : plus rien à faire
        $this->settings(['check_mode' => 'never']);
        $this->travel(1)->days();
        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/courier/missions')->assertJsonPath('meta.vehicle.check_needed', false);
    }

    public function test_fuel_fines_and_claims_on_pay(): void
    {
        $moto = $this->moto(['courier_id' => $this->courierA->id]);
        // Carburant à la charge du livreur (par défaut) : rien sur la paie ; reçu demandé
        Sanctum::actingAs($this->courierA->user);
        $this->post('/api/v1/courier/vehicle/fuel', ['amount' => 3000, 'liters' => 3.4, 'odometer' => 18200], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/v1/courier/vehicle/fuel', ['amount' => 3000, 'liters' => 3.4, 'odometer' => 18200, 'photos' => [UploadedFile::fake()->image('recu.jpg')]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.fuel_month.amount', 3000);
        $this->assertSame(0, CourierEarning::where('courier_id', $this->courierA->id)->count());

        // Remboursement des pleins
        $this->settings(['fuel_mode' => 'refund', 'fuel_receipt_required' => false, 'damage_charge_courier' => true, 'damage_cap_month' => 10000]);
        Sanctum::actingAs($this->courierA->user);
        $this->post('/api/v1/courier/vehicle/fuel', ['amount' => 2500], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(2500, (int) CourierEarning::where('type', EarningType::FuelRefund->value)->sum('amount'));

        // Amende retenue au livreur qui avait la moto ; sinistre : part des dégâts dans la limite du plafond
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/vehicles/{$moto->id}/logs", ['kind' => 'fine', 'amount' => 5000, 'label' => 'Stationnement Plateau'])->assertOk();
        $this->postJson("/api/v1/vehicles/{$moto->id}/logs", ['kind' => 'claim', 'amount' => 60000, 'courier_share' => 15000, 'status' => 'broken'])->assertOk()
            ->assertJsonPath('data.status', 'broken');
        $charges = CourierEarning::where('courier_id', $this->courierA->id)->where('type', EarningType::VehicleCharge->value)->orderBy('id')->pluck('amount')->all();
        $this->assertSame([-5000, -10000], $charges);

        // Suppression de l'amende : retenue annulée
        $fine = $moto->logs()->where('kind', 'fine')->sole();
        $this->deleteJson("/api/v1/vehicles/logs/{$fine->id}")->assertOk();
        $this->assertSame(-10000, (int) CourierEarning::where('courier_id', $this->courierA->id)->where('type', EarningType::VehicleCharge->value)->sum('amount'));
    }

    public function test_lease_purchase_payroll_and_cash(): void
    {
        $moto = $this->moto();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/vehicles/{$moto->id}/leases", ['courier_id' => $this->courierA->id, 'kind' => 'purchase', 'amount' => 3000,
            'period' => 'day', 'total_amount' => 9000, 'starts_on' => '2026-11-21', 'payment' => 'payroll'])->assertOk()
            ->assertJsonPath('data.ownership', 'lease')->assertJsonPath('data.courier.id', $this->courierA->id)
            ->assertJsonPath('data.leases.0.due', 9000);

        // Retenue sur la paie de ce qui est dû (3 jours) : prix total atteint, la moto est au livreur
        app(VehicleDaily::class)->forCompany($this->company->fresh());
        $this->assertSame(-9000, (int) CourierEarning::where('type', EarningType::VehicleLease->value)->sum('amount'));
        $lease = VehicleLease::sole();
        $this->assertSame('completed', $lease->status);
        $this->assertSame(['courier', $this->courierA->id], [$moto->fresh()->ownership, $moto->fresh()->owner_courier_id]);

        // Location payée en caisse : retard, rappels, versement
        Notification::fake();
        $car = Vehicle::create(['company_id' => $this->company->id, 'type' => 'voiture', 'plate' => 'IJ-7788-CI']);
        $this->postJson("/api/v1/vehicles/{$car->id}/leases", ['courier_id' => $this->courierB->id, 'kind' => 'rental', 'amount' => 2000, 'period' => 'day',
            'starts_on' => '2026-11-13', 'payment' => 'cash'])->assertOk();
        $rental = VehicleLease::where('kind', 'rental')->sole();
        $balance = app(VehicleLeases::class)->balance($rental);
        $this->assertSame([20000, 10], [$balance['late'], $balance['late_periods']]);
        $this->assertSame(2, app(VehicleLeases::class)->remindLate($rental));
        Notification::assertSentTo($this->courierB->user, VehicleAlert::class);
        Notification::assertSentTo($this->admin, VehicleAlert::class);
        $this->postJson("/api/v1/vehicles/leases/{$rental->id}/payments", ['amount' => 20000])->assertOk()->assertJsonPath('data.leases.0.late', 0);
    }

    public function test_daily_allowances(): void
    {
        $moto = $this->moto(['courier_id' => $this->courierA->id]);
        $this->settings(['fuel_mode' => 'allowance', 'fuel_allowance' => 2000, 'personal_allowance' => 1000]);
        // Moto confiée depuis plusieurs jours
        VehicleAssignment::query()->update(['starts_at' => now()->subDays(5)]);
        $this->workedYesterday();
        $daily = app(VehicleDaily::class);
        $this->assertSame(1, $daily->forCompany($this->company->fresh())['allowances']);
        $this->assertSame(0, $daily->forCompany($this->company->fresh())['allowances'], 'une fois par jour');
        $this->assertSame(2000, (int) CourierEarning::where('type', EarningType::FuelAllowance->value)->sum('amount'));

        // Véhicule personnel : indemnité, pas de dotation
        $moto->forceFill(['ownership' => 'courier'])->save();
        CourierEarning::query()->toBase()->delete();
        $this->assertSame(1, $daily->forCompany($this->company->fresh())['allowances']);
        $this->assertSame([EarningType::VehicleAllowance], CourierEarning::pluck('type')->all());
        $this->assertGreaterThan(0, OrderAssignment::count());
    }

    public function test_field_event_puts_vehicle_down_and_back(): void
    {
        $moto = $this->moto(['courier_id' => $this->courierA->id]);
        $this->courierA->update(['is_available' => true]);
        Sanctum::actingAs($this->courierA->user);
        $id = $this->post('/api/v1/courier/field-events', ['type' => 'breakdown', 'capacity' => 'blocked', 'cost' => 2500], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $this->assertSame('broken', $moto->fresh()->status);
        $repair = $moto->logs()->sole();
        $this->assertSame(['repair', 2500, $id], [$repair->kind, $repair->amount, $repair->field_event_id]);

        $this->postJson("/api/v1/courier/field-events/{$id}/resume")->assertOk();
        $this->assertSame('active', $moto->fresh()->status);
        $this->assertSame(FieldEvent::RESOLVED, FieldEvent::find($id)->status);
    }

    public function test_settings_stats_and_courier_view(): void
    {
        $moto = $this->moto(['courier_id' => $this->courierA->id]);
        Sanctum::actingAs($this->admin);
        $saved = $this->patchJson("/api/v1/companies/{$this->company->id}", ['vehicle_settings' => [
            'check_mode' => 'weekly', 'fuel_mode' => 'allowance', 'reminder_days' => [7, 30, 30],
            'default_plans' => ['moto' => [['label' => 'Vidange', 'every_km' => 1500]]],
        ]])->assertOk()->json('data.vehicle_settings');
        $this->assertSame(['weekly', [30, 7], 'vidange'], [$saved['check_mode'], $saved['reminder_days'], $saved['default_plans']['moto'][0]['key']]);

        $this->postJson("/api/v1/vehicles/{$moto->id}/logs", ['kind' => 'fuel', 'amount' => 6000, 'liters' => 6, 'odometer' => 18100])->assertOk();
        $this->postJson("/api/v1/vehicles/{$moto->id}/logs", ['kind' => 'fuel', 'amount' => 3000, 'liters' => 3, 'odometer' => 18300])->assertOk();
        $stats = $this->getJson('/api/v1/vehicles/stats?from=2026-11-01&to=2026-11-30')->assertOk()->json('data');
        $this->assertSame(9000, $stats['totals']['fuel']);
        $this->assertEquals([300, 3.0], [$stats['vehicles'][0]['km'], $stats['vehicles'][0]['consumption']]);
        $this->getJson("/api/v1/vehicles/{$moto->id}")->assertOk()->assertJsonPath('data.costs.fuel', 9000);
        $this->admin->unsetRelation('company');
        $this->getJson('/api/v1/vehicles/catalog')->assertOk()->assertJsonPath('data.settings.check_mode', 'weekly');

        $this->courierA->user->unsetRelation('company');
        $this->courierA->unsetRelation('company');
        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/courier/vehicle')->assertOk()->assertJsonPath('data.vehicle.plate', 'AB-1234-CI')
            ->assertJsonPath('data.settings.fuel_mode', 'allowance')->assertJsonCount(4, 'data.maintenance');
        $doc = VehicleDocument::create(['company_id' => $this->company->id, 'vehicle_id' => $moto->id, 'kind' => 'insurance', 'ends_on' => '2027-01-01']);
        $this->assertNotNull($doc);
    }
}
