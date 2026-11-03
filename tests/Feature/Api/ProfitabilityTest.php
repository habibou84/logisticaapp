<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\BillingPlan;
use App\Models\CourierEarning;
use App\Models\FixedCost;
use App\Models\Order;
use App\Models\Vehicle;
use App\Models\VehicleLog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Statistiques › Rentabilité et Objectifs : revenus (frais, forfaits au prorata), coûts directs (paie, véhicules,
 * frais), frais fixes, marge par client, zone, livreur et contrat ; objectifs du mois et tendances.
 */
class ProfitabilityTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->buildWorld();
        $this->travelTo(CarbonImmutable::parse('2026-12-15 10:00', 'UTC'));
    }

    private function deliver(): Order
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();

        return $order->fresh();
    }

    public function test_margin_by_client_zone_courier_and_contract(): void
    {
        $order = $this->deliver();
        CourierEarning::query()->withoutGlobalScopes()->delete();
        $earning = fn (array $data) => CourierEarning::create(['company_id' => $this->company->id, ...$data]);
        $earning(['courier_id' => $this->courierB->id, 'order_id' => $order->id, 'type' => 'delivery', 'amount' => 500]);
        $earning(['courier_id' => $this->courierA->id, 'type' => 'salary', 'amount' => 3000]);
        $vehicle = Vehicle::create(['company_id' => $this->company->id, 'type' => 'moto', 'plate' => 'AB-1234-CI', 'courier_id' => $this->courierA->id]);
        VehicleLog::create(['company_id' => $this->company->id, 'vehicle_id' => $vehicle->id, 'kind' => 'fuel', 'done_on' => '2026-12-10', 'amount' => 2000, 'paid_by' => 'company']);
        $plan = BillingPlan::create([
            'company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'formula' => 'distribution_contract', 'monthly_fee' => 31000,
            'included_quantity' => 10, 'overage_price' => 500, 'delivery_delay' => '24h', 'starts_on' => '2026-12-01',
        ]);
        FixedCost::create(['company_id' => $this->company->id, 'label' => 'Loyer', 'category' => 'rent', 'amount' => 31000, 'frequency' => 'monthly', 'starts_on' => '2026-01-01']);

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/stats/profitability?from=2026-12-01&to=2026-12-15')->assertOk()->json('data');
        $t = $data['totals'];
        $fee = $order->delivery_fee + $order->surcharges_total;
        $this->assertSame($fee + 15000, $t['revenue']);
        $this->assertSame(['courier_pay' => 500, 'courier_fixed' => 3000, 'vehicles' => 2000, 'expenses' => 0, 'losses' => 0], $t['costs']);
        $this->assertSame(15000, $t['fixed_costs']);
        $this->assertSame($fee + 15000 - 5500 - 15000, $t['margin']);
        $this->assertSame(1, $t['delivered']);

        $client = collect($data['clients'])->firstWhere('id', $this->merchant->id);
        $this->assertSame([$fee + 15000, 5500, 15000], [$client['revenue'], $client['direct_costs'], $client['fixed_share']]);
        $this->assertSame($client['margin'] < 0 ? 'loss' : ($client['margin_rate'] < 10 ? 'renegotiate' : 'profitable'), $client['verdict']);

        $zone = collect($data['zones'])->firstWhere('id', $this->yopougon->id);
        $this->assertSame([1, $fee, 5500 + 15000], [$zone['delivered'], $zone['revenue'], $zone['costs']]);

        $courierA = collect($data['couriers'])->firstWhere('id', $this->courierA->id);
        $this->assertSame([1, 3000, 2000], [$courierA['missions'], $courierA['pay'], $courierA['vehicle']]);

        $contract = collect($data['contracts'])->firstWhere('id', $plan->id);
        $this->assertSame([10, 0, 'underused'], [$contract['included'], $contract['used'], $contract['verdict']]);

        // Réservé à qui voit la caisse
        Sanctum::actingAs($this->userWithRole(Role::HubAgent));
        $this->getJson('/api/v1/stats/profitability')->assertForbidden();
    }

    public function test_a_mad_courier_costs_go_to_his_contract(): void
    {
        $plan = BillingPlan::create([
            'company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'formula' => 'mad_contract', 'monthly_fee' => 62000,
            'included_quantity' => 20, 'delivery_delay' => '24h', 'starts_on' => '2026-12-01',
        ]);
        $plan->couriers()->attach($this->courierA->id);
        CourierEarning::create(['company_id' => $this->company->id, 'courier_id' => $this->courierA->id, 'type' => 'mad_days', 'amount' => 10000]);

        Sanctum::actingAs($this->admin);
        $contract = collect($this->getJson('/api/v1/stats/profitability?from=2026-12-01&to=2026-12-15')->json('data.contracts'))->firstWhere('id', $plan->id);
        $this->assertSame([30000, 10000, 20000], [$contract['revenue'], $contract['mad_cost'], $contract['mad_margin']]);
    }

    public function test_fixed_costs_are_managed_and_prorated(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/fixed-costs', ['label' => 'Salaires bureau', 'category' => 'staff', 'amount' => 300000, 'frequency' => 'monthly', 'starts_on' => '2026-11-01'])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/fixed-costs', ['label' => 'Enseigne', 'category' => 'marketing', 'amount' => 50000, 'frequency' => 'once', 'starts_on' => '2026-12-10'])->assertCreated();
        $this->postJson('/api/v1/fixed-costs', ['label' => '', 'category' => 'x', 'amount' => 0, 'frequency' => 'monthly', 'starts_on' => '2026-12-01'])
            ->assertJsonValidationErrors(['label', 'category', 'amount']);
        $this->assertCount(2, $this->getJson('/api/v1/fixed-costs')->assertOk()->json('data'));

        $costs = FixedCost::query()->get();
        $from = CarbonImmutable::parse('2026-12-01');
        $this->assertSame(300000 + 50000, (int) $costs->sum(fn (FixedCost $c) => $c->amountBetween($from, $from->endOfMonth())));
        $this->assertSame(150000, FixedCost::find($id)->amountBetween(CarbonImmutable::parse('2026-11-16'), CarbonImmutable::parse('2026-11-30')));

        $this->putJson("/api/v1/fixed-costs/{$id}", ['label' => 'Salaires bureau', 'category' => 'staff', 'amount' => 200000, 'frequency' => 'monthly', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30'])
            ->assertOk()->assertJsonPath('data.ends_on', '2026-11-30');
        $this->assertSame(0, FixedCost::find($id)->amountBetween($from, $from->endOfMonth()));
        $this->deleteJson("/api/v1/fixed-costs/{$id}")->assertOk();

        // Saisie réservée à « Encaisser et reverser »
        Sanctum::actingAs($this->dispatcher);
        if (! $this->dispatcher->can('finance.manage')) {
            $this->postJson('/api/v1/fixed-costs', ['label' => 'X', 'category' => 'other', 'amount' => 1, 'frequency' => 'once', 'starts_on' => '2026-12-01'])->assertForbidden();
        }
    }

    public function test_goals_projection_and_comparisons(): void
    {
        $this->deliver();
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['stats_settings' => ['goals' => ['delivered' => 10, 'success_rate' => 90], 'renegotiate_margin' => 15]])
            ->assertOk()->assertJsonPath('data.stats_settings.goals.delivered', 10)->assertJsonPath('data.stats_settings.renegotiate_margin', 15);

        $data = $this->getJson('/api/v1/stats/performance')->assertOk()->json('data');
        $this->assertCount(13, $data['months']);
        $this->assertSame('2026-12', $data['current']['month']);
        $goal = $data['current']['goals']['delivered'];
        // 1 livrée en 15 jours : 2 au rythme actuel sur 31 jours, loin des 10
        $this->assertSame([10, 1, 2, 10, false], [$goal['target'], $goal['realized'], $goal['projection'], $goal['percent'], $goal['on_track']]);
        $this->assertSame(100.0, (float) $data['current']['goals']['success_rate']['realized']);
        $this->assertTrue($data['current']['goals']['success_rate']['on_track']);
        $this->assertSame(0, $data['comparisons']['delivered']['last_year']);

        // Forfait mensuel : compté jusqu'à aujourd'hui, et pour le mois entier dans la projection (pas multiplié)
        BillingPlan::create([
            'company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'formula' => 'distribution_contract', 'monthly_fee' => 31000,
            'included_quantity' => 10, 'overage_price' => 500, 'delivery_delay' => '24h', 'starts_on' => '2026-12-01',
        ]);
        $fees = $this->getJson('/api/v1/stats/performance')->json('data.comparisons.revenue');
        $variable = $fees['current'] - 15000;
        $this->assertSame((int) round($variable * 31 / 15) + 31000, $fees['projection']);
        $this->assertTrue($data['goals_set']);

        // Sans la caisse : pas de montants
        Sanctum::actingAs($this->dispatcher);
        if (! $this->dispatcher->can('finance.view')) {
            $data = $this->getJson('/api/v1/stats/performance')->json('data');
            $this->assertNull($data['months'][12]['revenue']);
            $this->assertNull($data['current']['goals']['revenue']);
        }
    }
}
