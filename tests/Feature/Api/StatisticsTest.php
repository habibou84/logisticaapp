<?php

namespace Tests\Feature\Api;

use App\Enums\EarningType;
use App\Enums\Role;
use App\Models\CourierEarning;
use App\Models\IncidentReason;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderExpense;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Statistiques du back-office : vue d'ensemble, livraisons, livreurs, droits.
 */
class StatisticsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTimezone('Africa/Abidjan')->setDate(2026, 10, 14)->setTime(10, 0));
        $this->buildWorld();
    }

    private function move(Order $order, string $status, array $extra = []): void
    {
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => $status, ...$extra])->assertOk();
    }

    private function pickedUpOrder(): Order
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
        Sanctum::actingAs($this->courierA->user);
        $this->move($order, 'picked_up');

        return $order;
    }

    private function sendOut(Order $order): void
    {
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();
        Sanctum::actingAs($this->courierB->user);
        $this->move($order, 'out_for_delivery');
    }

    /**
     * Une course livrée du premier coup, une livrée après un échec, une en attente.
     */
    private function activity(): void
    {
        $first = $this->pickedUpOrder();
        $this->travel(30)->minutes();
        $this->sendOut($first);
        $this->move($first, 'delivered', ['collected_amount' => 10000]);

        $second = $this->pickedUpOrder();
        $this->sendOut($second);
        $this->move($second, 'delivery_failed', ['incident_reason_id' => IncidentReason::where('code', 'unreachable')->value('id')]);
        $this->travel(2)->hours();
        $this->sendOut($second);
        $this->move($second, 'delivered', ['collected_amount' => 10000]);

        $this->createOrder();
    }

    public function test_overview_counts_amounts_series_and_comparison(): void
    {
        $this->activity();
        $fee = Order::first()->delivery_fee + Order::first()->surcharges_total;

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/stats/overview?from=2026-10-01&to=2026-10-14')->assertOk()->json('data');

        $this->assertSame(['from' => '2026-09-17', 'to' => '2026-09-30'], $data['previous_period']);
        $this->assertSame(3, $data['kpis']['created']);
        $this->assertSame(2, $data['kpis']['delivered']);
        $this->assertSame(1, $data['kpis']['failed_attempts']);
        $this->assertEquals(100, $data['kpis']['success_rate']);
        $this->assertSame(2 * $fee, $data['kpis']['revenue']);
        $this->assertSame(20000, $data['kpis']['collected']);
        $this->assertSame(0, $data['previous_kpis']['created']);

        $this->assertCount(14, $data['series']);
        $this->assertSame(['date' => '2026-10-14', 'created' => 3, 'delivered' => 2, 'revenue' => 2 * $fee], $data['series'][13]);
        // Mercredi 14 octobre (Abidjan = UTC) : deux courses vers 10 h, une vers 12 h
        $this->assertSame([2, 1, 3], [$data['heatmap'][2][10], $data['heatmap'][2][12], array_sum($data['heatmap'][2])]);
    }

    public function test_deliveries_quality(): void
    {
        $this->activity();

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/stats/deliveries')->assertOk()->json('data');

        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-14'], $data['period']);
        $this->assertSame(2, $data['delivered']);
        $this->assertEquals(50, $data['first_attempt_rate']);
        $this->assertEquals(1.5, $data['average_attempts']);
        $this->assertEquals(100, $data['same_day_rate']);
        $this->assertSame(1, $data['failed_attempts']);
        $this->assertSame([['label' => IncidentReason::where('code', 'unreachable')->value('label'), 'count' => 1]], $data['failure_reasons']);
        $this->assertSame(['delivered' => 2, 'rescheduled' => 0, 'returned' => 0, 'lost' => 0, 'cancelled' => 0], $data['outcomes']);
        $this->assertNotNull($data['delays']['total']);
    }

    public function test_couriers_performance(): void
    {
        $this->activity();

        Sanctum::actingAs($this->admin);
        $rows = collect($this->getJson('/api/v1/stats/couriers')->assertOk()->json('data.couriers'))->keyBy('id');

        $awa = $rows[$this->courierB->id];
        $this->assertSame(['Awa Livreuse', 2, 1], [$awa['name'], $awa['delivered'], $awa['failed']]);
        $this->assertEquals(66.7, $awa['success_rate']);
        $this->assertSame(20000, $awa['collected']);
        $this->assertSame(1, $awa['days_worked']);

        $koffi = $rows[$this->courierA->id];
        $this->assertSame([0, 2], [$koffi['delivered'], $koffi['pickups']]);
    }

    public function test_access_rules(): void
    {
        $this->activity();

        // Dispatcheur : statistiques sans les montants
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/stats/overview')->assertOk()->assertJsonPath('data.kpis.revenue', null)->assertJsonPath('data.series.0.revenue', null);
        $this->getJson('/api/v1/stats/couriers')->assertOk()->assertJsonPath('data.couriers.0.collected', null);

        // Caissier : pas l'onglet livreurs
        Sanctum::actingAs($this->userWithRole(Role::Cashier));
        $this->getJson('/api/v1/stats/couriers')->assertForbidden();

        // E-commerçant et livreur : rien
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/stats/overview')->assertForbidden();
        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/stats/overview')->assertForbidden();

        // Période trop longue
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/stats/overview?from=2024-01-01&to=2026-10-14')->assertJsonValidationErrors('from');
    }

    public function test_merchants_activity_and_merchants_to_relaunch(): void
    {
        // Une boutique qui a commandé il y a deux mois, plus depuis
        $sleeping = Merchant::factory()->create(['company_id' => $this->company->id, 'business_name' => 'Boutique Endormie', 'pickup_zone_id' => $this->cocody->id]);
        $this->travel(-60)->days();
        $this->createOrder([], $this->admin, $sleeping);
        $this->travel(60)->days();

        $this->activity();

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/stats/merchants')->assertOk()->json('data');

        $this->assertCount(1, $data['merchants']);
        $row = $data['merchants'][0];
        $this->assertSame(['Boutique Test', 3, 2, 0], [$row['name'], $row['created'], $row['delivered'], $row['returned']]);
        $this->assertEquals(100, $row['success_rate']);
        $this->assertSame(20000, $row['collected']);
        $this->assertNotNull($row['to_pay']);

        $this->assertSame(['Boutique Endormie'], array_column($data['inactive'], 'name'));
        $this->assertSame(60, $data['inactive'][0]['days']);
        $this->assertSame(1, $data['totals']['active']);
        $this->assertSame(1, $data['totals']['inactive']);
        $this->assertEquals(100, $data['totals']['top5_share']);

        // Sans accès à la caisse : pas de montants ; sans accès aux marchands : rien
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/stats/merchants')->assertOk()->assertJsonPath('data.merchants.0.revenue', null)->assertJsonPath('data.totals.to_pay', null);
        Sanctum::actingAs($this->userWithRole(Role::Cashier));
        $this->getJson('/api/v1/stats/merchants')->assertOk();
    }

    public function test_zones_grouped_by_commune(): void
    {
        $this->activity();
        // Livraison dans un quartier : compte dans sa commune
        $this->rule($this->grid, $this->cocody, $this->angre, 1000);
        $this->pickedUpOrder()->update(['delivery_zone_id' => $this->angre->id]);

        Sanctum::actingAs($this->admin);
        $rows = collect($this->getJson('/api/v1/stats/zones')->assertOk()->json('data.zones'))->keyBy('name');

        $this->assertSame([3, 2, 1], [$rows['Yopougon']['created'], $rows['Yopougon']['delivered'], $rows['Yopougon']['failed']]);
        $this->assertEquals(66.7, $rows['Yopougon']['success_rate']);
        $this->assertSame(Order::first()->delivery_fee + Order::first()->surcharges_total, $rows['Yopougon']['average_fee']);
        $this->assertSame(1, $rows['Cocody']['created']);
        $this->assertArrayNotHasKey('Angré', $rows->all());
    }

    public function test_finances_revenue_costs_margin_and_circulation(): void
    {
        $this->activity();
        $fees = 2 * (Order::first()->delivery_fee + Order::first()->surcharges_total);

        CourierEarning::create(['company_id' => $this->company->id, 'courier_id' => $this->courierB->id, 'type' => EarningType::Delivery, 'amount' => 700, 'description' => 'Livraison']);
        OrderExpense::create(['company_id' => $this->company->id, 'order_id' => Order::first()->id, 'type' => 'transport', 'amount' => 300, 'paid_by' => 'company', 'billed_to' => 'company']);

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/stats/finances')->assertOk()->json('data');

        $this->assertSame($fees, $data['revenue']['delivery']);
        $this->assertSame($fees, $data['revenue']['total']);
        $this->assertSame(['courier_pay' => 700, 'expenses' => 300, 'lost_compensations' => 0, 'total' => 1000], $data['costs']);
        $this->assertSame($fees - 1000, $data['margin']);
        $this->assertSame((int) round(($fees - 1000) / 2), $data['margin_per_order']);
        $this->assertSame([['month' => '2026-10', 'revenue' => $fees, 'costs' => 1000, 'margin' => $fees - 1000]], $data['months']);
        // Les 20 000 F encaissés sont encore chez la livreuse
        $this->assertSame(20000, $data['circulation']['with_couriers']);
        $this->assertSame(20000 - $fees, $data['circulation']['to_merchants']);

        // Réservé aux comptes qui voient la caisse
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/stats/finances')->assertForbidden();
    }

    public function test_merchant_sees_only_their_own_statistics(): void
    {
        $this->activity();
        // Une course d'une autre boutique de l'entreprise : jamais comptée
        $other = Merchant::factory()->create(['company_id' => $this->company->id, 'pickup_zone_id' => $this->cocody->id]);
        $this->createOrder([], $this->admin, $other);

        Sanctum::actingAs($this->merchantUser);
        $data = $this->getJson('/api/v1/merchant/stats')->assertOk()->json('data');

        $this->assertSame([3, 2, 1], [$data['kpis']['created'], $data['kpis']['delivered'], $data['kpis']['failed_attempts']]);
        $this->assertSame(20000, $data['kpis']['collected']);
        $this->assertEquals(50, $data['first_attempt_rate']);
        $this->assertSame('Yopougon', $data['zones'][0]['name']);
        $this->assertSame(1, $data['failure_reasons'][0]['count']);
        $this->assertCount(14, $data['series']);

        // Employé de la boutique sans accès aux paiements : pas de montants
        Sanctum::actingAs($this->userWithRole(Role::MerchantStaff, ['merchant_id' => $this->merchant->id]));
        $this->getJson('/api/v1/merchant/stats')->assertOk()->assertJsonPath('data.kpis.collected', null);

        // Personnel de l'entreprise : pas de « mes statistiques »
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/merchant/stats')->assertForbidden();
    }

    public function test_advanced_statistics_depend_on_the_plan(): void
    {
        $basic = Plan::create(['name' => 'Essentiel', 'price_monthly' => 25000, 'features' => [], 'is_active' => true, 'position' => 1]);
        $this->company->update(['plan_id' => $basic->id]);

        Sanctum::actingAs($this->admin->fresh());
        $this->getJson('/api/v1/stats/overview')->assertOk();
        $this->getJson('/api/v1/stats/merchants')->assertOk();
        $this->getJson('/api/v1/stats/zones')->assertForbidden();
        $this->getJson('/api/v1/stats/finances')->assertForbidden();

        $basic->update(['features' => ['advanced_stats']]);
        Sanctum::actingAs($this->admin->fresh());
        $this->getJson('/api/v1/stats/zones')->assertOk();
        $this->getJson('/api/v1/stats/finances')->assertOk();
    }
}
