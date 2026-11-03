<?php

namespace Tests\Feature\Api;

use App\Enums\Activity;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\ClientInvoice;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Statistiques › Clients avancé et Analyse croisée : cohortes, clients en baisse, 80/20, paiements ; tableau croisé.
 */
class ClientInsightsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->buildWorld();
        $this->travelTo(CarbonImmutable::parse('2026-12-15 10:00', 'UTC'));
    }

    private function order(string $createdAt, array $attributes = [], ?Merchant $merchant = null): Order
    {
        $order = $this->createOrder([], null, $merchant);
        $order->forceFill(['created_at' => $createdAt, ...$attributes])->saveQuietly();

        return $order;
    }

    public function test_cohorts_decline_and_payments(): void
    {
        // Client arrivé en septembre, actif en octobre, plus rien en novembre et décembre
        $this->order('2026-09-05 10:00');
        for ($i = 0; $i < 18; $i++) {
            $this->order('2026-10-0'.($i % 9 + 1).' 10:00');
        }
        ClientInvoice::create(['company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'activity' => 'distribution', 'number' => 'F-1',
            'period' => '2026-10-01', 'status' => 'paid', 'total' => 50000, 'amount_paid' => 50000, 'issued_at' => '2026-11-01', 'due_at' => '2026-11-15', 'paid_at' => '2026-11-21 10:00']);
        ClientInvoice::create(['company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'activity' => 'distribution', 'number' => 'F-2',
            'period' => '2026-08-01', 'status' => 'issued', 'total' => 30000, 'amount_paid' => 10000, 'issued_at' => '2026-09-01', 'due_at' => '2026-09-15']);

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/stats/clients?from=2026-12-01&to=2026-12-15')->assertOk()->json('data');
        $sept = collect($data['cohorts'])->firstWhere('month', '2026-09');
        $this->assertSame(1, $sept['size']);
        $this->assertSame([100.0, 0.0, 0.0, null], [(float) $sept['retention'][1], (float) $sept['retention'][2], (float) $sept['retention'][3], $sept['retention'][6]]);

        $decline = collect($data['decline'])->firstWhere('id', $this->merchant->id);
        $this->assertSame([0, -100], [$decline['recent'], $decline['change']]);

        $p = $data['payments'];
        $this->assertSame(20, $p['average_days']);
        // Échue le 15 septembre : plus de 90 jours
        $this->assertSame(['not_due' => 0, '0_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 20000], $p['buckets']);
        $this->assertSame(20000, $p['clients'][0]['overdue']);
    }

    public function test_pareto_and_pivot(): void
    {
        $other = Merchant::factory()->create(['company_id' => $this->company->id, 'business_name' => 'Petit client', 'pickup_zone_id' => $this->cocody->id]);
        $other->syncActivities([Activity::Ecommerce]);
        foreach ([[$this->merchant, 4], [$other, 1]] as [$merchant, $n]) {
            for ($i = 0; $i < $n; $i++) {
                $o = $this->order('2026-12-0'.($i + 1).' 09:30', ['status' => OrderStatus::Delivered, 'delivered_at' => '2026-12-0'.($i + 1).' 15:00'], $merchant);
                (new MerchantLedgerEntry)->forceFill(['company_id' => $this->company->id, 'merchant_id' => $merchant->id, 'order_id' => $o->id, 'type' => 'delivery_fee', 'amount' => -1000, 'created_at' => '2026-12-0'.($i + 1).' 15:00'])->save();
            }
        }

        Sanctum::actingAs($this->admin);
        $p = $this->getJson('/api/v1/stats/clients?from=2026-12-01&to=2026-12-15')->json('data.pareto');
        $this->assertSame([5000, 2, 1, 80.0], [$p['total'], $p['clients'], $p['clients_for_80'], (float) $p['top_share']]);

        $pivot = $this->getJson('/api/v1/stats/pivot?from=2026-12-01&to=2026-12-15&rows=client&cols=weekday&metric=courses')->assertOk()->json('data');
        $row = collect($pivot['table'])->firstWhere('key', (string) $this->merchant->id);
        $this->assertSame(4, $row['total']);
        // 1er décembre 2026 : un mardi
        $tuesday = collect($pivot['columns'])->search(fn ($c) => $c['label'] === 'Mardi');
        $this->assertSame(1, $row['cells'][$tuesday]);
        $this->assertSame(5, $pivot['totals']['total']);
        $fees = (int) Order::query()->where('merchant_id', $this->merchant->id)->get()->sum(fn ($o) => $o->delivery_fee + $o->surcharges_total);
        $this->assertSame($fees, collect($this->getJson('/api/v1/stats/pivot?from=2026-12-01&to=2026-12-15&rows=client&metric=revenue')->json('data.table'))->firstWhere('key', (string) $this->merchant->id)['total']);
        $this->getJson('/api/v1/stats/pivot?rows=client&cols=client&metric=courses')->assertJsonValidationErrors('cols');

        // Sans la caisse : pas de chiffre d'affaires
        Sanctum::actingAs($this->userWithRole(Role::HubAgent));
        if (! $this->userWithRole(Role::HubAgent)->can('finance.view')) {
            $this->getJson('/api/v1/stats/pivot?rows=zone&metric=revenue')->assertForbidden();
        }
    }
}
