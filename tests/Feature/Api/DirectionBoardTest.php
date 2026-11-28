<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\BillingPlan;
use App\Models\ClientInvoice;
use App\Models\CourierEarning;
use App\Models\CourierShift;
use App\Models\Order;
use App\Models\OrderAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Tableau de direction › Vue d'ensemble : les activités côte à côte (chiffre d'affaires, marge, qualité, factures en
 * retard), douze mois de chiffre d'affaires, points à décider ; réservé à l'autorisation « direction.view », dans le
 * périmètre d'activités de la personne.
 */
class DirectionBoardTest extends TestCase
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

    public function test_activities_side_by_side_with_points_to_decide(): void
    {
        $order = $this->deliver();
        CourierEarning::query()->withoutGlobalScopes()->delete();
        CourierEarning::create(['company_id' => $this->company->id, 'courier_id' => $this->courierB->id, 'order_id' => $order->id, 'type' => 'delivery', 'amount' => 500]);
        // Contrat de distribution : forfait du mois, à échéance dans moins de 60 jours
        BillingPlan::create([
            'company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'formula' => 'distribution_contract', 'monthly_fee' => 31000,
            'included_quantity' => 10, 'overage_price' => 500, 'delivery_delay' => '24h', 'starts_on' => '2026-12-01', 'ends_on' => '2027-01-10',
        ]);
        // Facture de distribution en retard de trois mois
        ClientInvoice::create(['company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'activity' => 'distribution', 'number' => 'F-2',
            'period' => '2026-08-01', 'status' => 'issued', 'total' => 30000, 'amount_paid' => 10000, 'issued_at' => '2026-09-01', 'due_at' => '2026-09-15']);

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/stats/direction?from=2026-12-01&to=2026-12-15')->assertOk()->json('data');

        $this->assertSame(['ecommerce', 'distribution', 'mad'], array_column($data['activities'], 'value'));
        $rows = collect($data['activities'])->keyBy('value');
        $fee = $order->delivery_fee + $order->surcharges_total;
        $this->assertSame($fee, $rows['ecommerce']['revenue']);
        $this->assertSame($fee - 500, $rows['ecommerce']['margin']);
        $this->assertSame(1, $rows['ecommerce']['delivered']);
        $this->assertSame('Taux de livraison', $rows['ecommerce']['quality']['label']);
        $this->assertNull($rows['ecommerce']['overdue']);
        // Forfait au prorata : 15 jours sur 31
        $this->assertSame(15000, $rows['distribution']['revenue']);
        $this->assertSame(20000, $rows['distribution']['overdue']);
        $this->assertSame(0, $rows['mad']['revenue']);

        $this->assertSame($fee + 15000, $data['totals']['revenue']);
        $this->assertSame($fee + 15000 - 500, $data['totals']['margin']);
        $this->assertSame(20000, $data['totals']['overdue']);
        $this->assertSame(['from' => '2026-11-16', 'to' => '2026-11-30'], $data['previous_period']);

        // Douze mois, le mois en cours en dernier
        $this->assertCount(12, $data['months']);
        $this->assertSame('2026-12', $data['months'][11]['month']);
        $this->assertSame($fee, $data['months'][11]['revenue']['ecommerce']);

        $topics = collect($data['decisions'])->map(fn ($d) => $d['activity'].'|'.$d['topic']);
        $this->assertContains('distribution|Trésorerie', $topics);
        $this->assertContains('distribution|Renouvellement', $topics);
        $this->assertContains('distribution|Forfait sous-utilisé', $topics);
        // Du plus grave au moins grave
        $order = ['critical' => 0, 'warning' => 1, 'info' => 2, 'good' => 3];
        $levels = collect($data['decisions'])->map(fn ($d) => $order[$d['level']])->all();
        $sorted = $levels;
        sort($sorted);
        $this->assertSame($sorted, $levels);
    }

    public function test_ecommerce_screen(): void
    {
        $order = $this->deliver();
        CourierEarning::query()->withoutGlobalScopes()->delete();
        CourierEarning::create(['company_id' => $this->company->id, 'courier_id' => $this->courierB->id, 'order_id' => $order->id, 'type' => 'delivery', 'amount' => 500]);

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/stats/direction/ecommerce?from=2026-12-01&to=2026-12-15')->assertOk()->json('data');
        $fee = $order->delivery_fee + $order->surcharges_total;
        $this->assertSame([1, $fee, $fee - 500], [$data['kpis']['delivered'], $data['kpis']['revenue'], $data['kpis']['margin_per_delivery']]);
        $this->assertSame(['created' => 1, 'picked_up' => 1, 'first_attempt' => 1, 'delivered' => 1, 'returned' => 0], array_column($data['funnel'], 'count', 'key'));
        $this->assertSame([$this->yopougon->id, $fee - 500], [$data['zones'][0]['id'], $data['zones'][0]['margin_per_delivery']]);
        $this->assertSame([$this->merchant->id, 100.0], [$data['clients'][0]['id'], (float) $data['clients'][0]['share']]);
        $this->assertCount(12, $data['months']);
    }

    public function test_mad_and_distribution_screens(): void
    {
        $mad = BillingPlan::create([
            'company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'formula' => 'mad_contract', 'monthly_fee' => 310000,
            'included_quantity' => 100, 'overage_price' => 500, 'starts_on' => '2026-12-01', 'ends_on' => '2027-01-10',
        ]);
        $mad->couriers()->attach($this->courierA->id);
        CourierShift::create(['company_id' => $this->company->id, 'courier_id' => $this->courierA->id, 'work_date' => '2026-12-02', 'kind' => 'mad',
            'billing_plan_id' => $mad->id, 'merchant_id' => $this->merchant->id, 'clocked_in_at' => '2026-12-02 08:10', 'late_minutes' => 10, 'method' => 'gps']);
        BillingPlan::create([
            'company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'formula' => 'distribution_contract', 'monthly_fee' => 31000,
            'included_quantity' => 10, 'overage_price' => 500, 'delivery_delay' => '24h', 'starts_on' => '2026-12-01',
        ]);
        ClientInvoice::create(['company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'activity' => 'distribution', 'number' => 'F-2',
            'period' => '2026-08-01', 'status' => 'issued', 'total' => 30000, 'amount_paid' => 10000, 'issued_at' => '2026-09-01', 'due_at' => '2026-09-15']);

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/stats/direction/mad?from=2026-12-01&to=2026-12-15')->assertOk()->json('data');
        $this->assertSame([1, 1, 310000, 1, 10], [$data['kpis']['contracts'], $data['kpis']['couriers'], $data['kpis']['monthly_fees'], $data['kpis']['to_renew'], $data['kpis']['late_minutes']]);
        $contract = $data['contracts'][0];
        $this->assertSame([$mad->id, 1, 10, true, '2027-01-10', 'underused'], [$contract['id'], $contract['couriers'], $contract['late_minutes'], $contract['renew'], $contract['ends_on'], $contract['verdict']]);
        $this->assertSame(150000, $data['kpis']['revenue']);
        $this->assertContains('Renouvellement', array_column($data['decisions'], 'topic'));

        $data = $this->getJson('/api/v1/stats/direction/distribution?from=2026-12-01&to=2026-12-15')->assertOk()->json('data');
        $this->assertSame([15000, 20000, 20000], [$data['kpis']['revenue'], $data['kpis']['overdue'], $data['kpis']['outstanding']]);
        $this->assertSame(['underused', 10], [$data['contracts'][0]['verdict'], $data['contracts'][0]['included']]);
        $this->assertSame(20000, $data['payments']['buckets']['over_90']);
        $this->assertContains('Trésorerie', array_column($data['decisions'], 'topic'));
    }

    public function test_teams_screen(): void
    {
        $order = $this->deliver();
        // Livraison chronométrée : acceptée en 2 min, partie 8 min plus tard, faite en 30 min
        $mission = OrderAssignment::where('order_id', $order->id)->where('type', 'delivery')->first();
        $mission->forceFill(['assigned_at' => '2026-12-15 09:00', 'accepted_at' => '2026-12-15 09:02', 'started_at' => '2026-12-15 09:10', 'completed_at' => '2026-12-15 09:40'])->save();

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/stats/direction/teams?from=2026-12-01&to=2026-12-15')->assertOk()->json('data');
        $courier = collect($data['couriers'])->firstWhere('id', $this->courierB->id);
        $this->assertSame([1, 30], [$courier['delivered'], $courier['minutes']]);
        $this->assertNull($courier['verdict']);
        $this->assertSame(2, $data['times']['missions']);
        $delivery = collect($data['times']['by_activity'])->firstWhere('activity', 'ecommerce');
        $this->assertGreaterThanOrEqual(30, $delivery['total']);
        $zone = collect($data['zones']['rows'])->firstWhere('id', $this->yopougon->id);
        $this->assertSame([1, 1, 30], [$zone['deliveries'], $zone['couriers'], $zone['minutes']]);
        $agent = collect($data['staff'])->firstWhere('id', $this->dispatcher->id);
        $this->assertSame(2, $agent['assigned']);
        $this->assertCount(6, $data['pay_months']);
        // Le livreur du ramassage et celui de la livraison ont travaillé
        $this->assertSame(2, $data['kpis']['couriers']);
    }

    public function test_reserved_to_the_permission_and_limited_to_the_scope(): void
    {
        // Sans l'autorisation : refusé, même avec les statistiques
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/stats/direction')->assertForbidden();

        // Autorisation donnée à un responsable limité à la distribution : sa seule activité
        $manager = $this->userWithRole(Role::ActivityManager, ['activities' => ['distribution']]);
        $manager->givePermissionTo('direction.view');
        Sanctum::actingAs($manager->fresh());
        $data = $this->getJson('/api/v1/stats/direction')->assertOk()->json('data');
        $this->assertSame(['distribution'], array_column($data['activities'], 'value'));
        $this->assertSame(0, $data['totals']['unallocated_costs']);

        // Hors de son périmètre : l'écran d'une autre activité est refusé, le sien et les équipes ouverts
        $this->getJson('/api/v1/stats/direction/ecommerce')->assertForbidden();
        $this->getJson('/api/v1/stats/direction/distribution')->assertOk();
        $this->getJson('/api/v1/stats/direction/teams')->assertOk();
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/stats/direction/teams')->assertForbidden();

        // Un client n'y a pas accès
        Sanctum::actingAs($this->merchantUser ?? $this->userWithRole(Role::MerchantOwner, ['merchant_id' => $this->merchant->id]));
        $this->getJson('/api/v1/stats/direction')->assertForbidden();
    }
}
