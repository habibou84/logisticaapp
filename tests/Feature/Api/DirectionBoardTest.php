<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\BillingPlan;
use App\Models\ClientInvoice;
use App\Models\CourierEarning;
use App\Models\Order;
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

        // Un client n'y a pas accès
        Sanctum::actingAs($this->merchantUser ?? $this->userWithRole(Role::MerchantOwner, ['merchant_id' => $this->merchant->id]));
        $this->getJson('/api/v1/stats/direction')->assertForbidden();
    }
}
