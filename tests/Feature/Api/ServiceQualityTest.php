<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\BillingPlan;
use App\Models\DailySnapshot;
use App\Models\IncidentReason;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Notifications\StatsAlertRaised;
use App\Services\Reports\StatsAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Statistiques › Délais et échecs : respect des délais par activité et par groupe, lots, analyse des échecs,
 * distribution ; relevé du soir et alertes du matin.
 */
class ServiceQualityTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->buildWorld();
        $this->travelTo(CarbonImmutable::parse('2026-12-15 18:00', 'UTC'));
    }

    private function delivered(string $planned, string $deliveredAt, array $attributes = []): Order
    {
        $order = $this->createOrder();
        $order->forceFill(['status' => OrderStatus::Delivered, 'delivery_scheduled_date' => $planned, 'delivered_at' => $deliveredAt,
            'picked_up_at' => CarbonImmutable::parse($deliveredAt)->subHour(), 'delivery_courier_id' => $this->courierB->id, ...$attributes])->saveQuietly();

        return $order;
    }

    private function failure(Order $order, string $code, string $at): void
    {
        OrderEvent::create(['order_id' => $order->id, 'type' => 'incident', 'from_status' => 'out_for_delivery', 'to_status' => 'delivery_failed',
            'incident_reason_id' => IncidentReason::where('code', $code)->value('id'), 'created_at' => $at]);
    }

    public function test_delays_failures_and_distribution(): void
    {
        $this->delivered('2026-12-10', '2026-12-10 15:00');
        $this->delivered('2026-12-10', '2026-12-12 10:00');
        $this->delivered('2026-12-11', '2026-12-12 10:00', ['activity' => 'distribution', 'internal_due_at' => '2026-12-12 12:00', 'shipment_type' => 'pli', 'shipment_quantity' => 4]);
        $late = $this->delivered('2026-12-11', '2026-12-13 10:00', ['activity' => 'distribution', 'internal_due_at' => '2026-12-12 12:00']);
        $this->failure($late, 'unreachable', '2026-12-12 09:00');
        $this->failure($late, 'unreachable', '2026-12-12 16:00');
        $failed = $this->createOrder();
        $this->failure($failed, 'wrong_address', '2026-12-13 11:00');

        $lot = BillingPlan::create([
            'company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'formula' => 'distribution_lot', 'activity' => 'distribution',
            'delivery_delay' => '48h', 'starts_on' => '2026-12-01', 'lot_label' => 'Relevés', 'lot_quantity' => 1, 'lot_price' => 1000, 'lot_due_on' => '2026-12-12',
        ]);
        $this->delivered('2026-12-05', '2026-12-11 10:00', ['activity' => 'distribution', 'billing_plan_id' => $lot->id]);

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/stats/quality?from=2026-12-01&to=2026-12-15')->assertOk()->json('data');
        $byActivity = collect($data['delays']['by_activity'])->keyBy('key');
        $this->assertSame([2, 1], [$byActivity['ecommerce']['total'], $byActivity['ecommerce']['on_time']]);
        // Distribution : à l'heure avant le délai interne ; sans délai, le jour prévu
        $this->assertSame([3, 1], [$byActivity['distribution']['total'], $byActivity['distribution']['on_time']]);
        $this->assertSame(['on_time', '2026-12-11'], [$data['lots'][0]['status'], $data['lots'][0]['finished_on']]);

        $f = $data['failures'];
        $this->assertSame(3, $f['total']);
        $this->assertSame(['Destinataire injoignable', 2], [$f['reasons'][0]['label'], $f['reasons'][0]['count']]);
        $this->assertSame(1, $f['hours'][16]);
        $this->assertSame(['Destinataire injoignable', 'Adresse introuvable ou erronée'], $f['matrix']['reasons']);
        $this->assertSame('Destinataire injoignable', $f['by_client'][0]['top_reason']);

        $d = $data['distribution'];
        $this->assertSame([3, 6], [$d['courses'], $d['units']]);
        $this->assertSame(4, collect($d['by_day'])->firstWhere('date', '2026-12-12')['units']);
        // 60 min pour 4 plis = 15 min par pli ; les deux autres : 60 min chacun (médiane 60)
        $this->assertSame(60, $d['minutes_per_unit']);
    }

    public function test_evening_snapshot_and_morning_alerts(): void
    {
        $old = $this->createOrder();
        $old->forceFill(['delivery_scheduled_date' => '2026-12-10', 'status' => OrderStatus::Confirmed])->saveQuietly();
        $stats = app(StatsAlerts::class);
        $stats->snapshot($this->company);
        $this->assertSame(1, DailySnapshot::query()->first()->data['untreated']);

        Sanctum::actingAs($this->admin);
        $history = $this->getJson('/api/v1/stats/quality?from=2026-12-01&to=2026-12-15')->json('data.history');
        $this->assertSame(['2026-12-15', 1], [$history[0]['date'], $history[0]['untreated']]);

        // Zone sous le seuil : 2 livrées, 8 échecs à Yopougon sur 7 jours
        for ($i = 0; $i < 2; $i++) {
            $this->delivered('2026-12-14', '2026-12-14 10:00');
        }
        for ($i = 0; $i < 8; $i++) {
            $this->failure($this->createOrder(), 'absent', '2026-12-14 1'.$i.':00');
        }
        Notification::fake();
        $alerts = $stats->send($this->company);
        $this->assertContains("zone:{$this->yopougon->id}", array_column($alerts, 'key'));
        Notification::assertSentTo($this->admin, StatsAlertRaised::class, fn ($n) => str_contains($n->body, '20 %'));
        // Pas renvoyée avant 7 jours ; seuil réglable (désactivé ici)
        $this->assertSame([], $stats->send($this->company));
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['stats_settings' => ['alerts' => ['zone_success_min' => null, 'whatsapp' => true]]])
            ->assertOk()->assertJsonPath('data.stats_settings.alerts.zone_success_min', null)->assertJsonPath('data.stats_settings.alerts.client_drop_percent', 30);
        $this->assertNotContains("zone:{$this->yopougon->id}", array_column($stats->detect($this->company->fresh()), 'key'));
    }

    public function test_a_client_in_decline_is_flagged(): void
    {
        // 3 mois avant : 30 courses par mois ; 30 derniers jours : 5
        foreach ([100, 70, 40] as $daysAgo) {
            for ($i = 0; $i < 30; $i++) {
                $this->createOrder()->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();
            }
        }
        for ($i = 0; $i < 5; $i++) {
            $this->createOrder();
        }
        $alerts = collect(app(StatsAlerts::class)->detect($this->company))->keyBy('key');
        $this->assertTrue($alerts->has("client:{$this->merchant->id}"));
        $this->assertStringContainsString('−83 %', $alerts["client:{$this->merchant->id}"]['body']);
    }
}
