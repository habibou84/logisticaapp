<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Activité des livreurs, suivi du jour : livraisons et ramassages faits sur prévus, colis et argent en main,
 * signaux à surveiller, missions du jour d'un livreur, distribution (plis, accusés, délai interne) ; réservé au dispatch.
 */
class CourierActivityTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->buildWorld();
    }

    public function test_daily_tracking_counts_each_courier_progress(): void
    {
        $done = $this->createOrder(['items_amount' => 10000]);
        $todo = $this->createOrder(['items_amount' => 5000]);
        Sanctum::actingAs($this->dispatcher);
        foreach ([$done, $todo] as $order) {
            $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
            $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();
        }
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$done->id}/status", ['status' => 'picked_up'])->assertOk();
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/orders/{$done->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $this->postJson("/api/v1/orders/{$done->id}/status", ['status' => 'delivered'])->assertOk();

        Sanctum::actingAs($this->dispatcher);
        $response = $this->getJson('/api/v1/couriers/activity')->assertOk();
        $rows = collect($response->json('data'))->keyBy('id');

        $b = $rows[$this->courierB->id];
        $this->assertSame([1, 2], [$b['deliveries']['done'], $b['deliveries']['total']]);
        $this->assertSame($done->fresh()->cod_amount, $b['cash_in_hand']);
        $this->assertSame('mission', $b['state']);
        $a = $rows[$this->courierA->id];
        $this->assertSame(['done' => 1, 'total' => 2], $a['pickups']);
        $this->assertSame(1, $response->json('totals.delivered'));
        $this->assertSame(50, $response->json('totals.progress'));

        // Missions du jour d'un livreur
        $missions = $this->getJson("/api/v1/couriers/{$this->courierB->id}/activity")->assertOk()->json('data');
        $this->assertCount(2, $missions);
        $this->assertSame(['completed', 'assigned'], array_column($missions, 'status'));

        // Rythme, réussite et fin estimée ; prochaine adresse de la mission à faire
        $this->assertSame(100, $b['success_rate']);
        // Une seule mission faite, pas d'habitude : pas encore de rythme pour estimer la fin
        $this->assertNull($b['estimated_end']);
        $this->assertSame(1, $b['remaining']);
        $this->assertArrayHasKey('habit', $b);

        // Jour passé : ce qui a été fait ce jour-là et ce qui était prévu ce jour-là sans être fait, rien en main
        $this->travelTo(now()->addDay());
        $past = $this->getJson('/api/v1/couriers/activity?date='.now()->subDay()->toDateString())->assertOk();
        $this->assertFalse($past->json('is_today'));
        $pastB = collect($past->json('data'))->firstWhere('id', $this->courierB->id);
        $this->assertSame([1, 2], [$pastB['deliveries']['done'], $pastB['deliveries']['total']]);
        $this->assertSame('done', $pastB['state']);
        $this->assertSame(0, $pastB['cash_in_hand']);
        $this->getJson('/api/v1/couriers/activity?date='.now()->addDays(60)->toDateString())->assertJsonValidationErrors('date');
        $this->travelBack();

        // Réservé au dispatch
        Sanctum::actingAs($this->userWithRole(Role::Cashier));
        $this->getJson('/api/v1/couriers/activity')->assertForbidden();
    }

    public function test_end_of_day_is_estimated_from_the_pace_and_flagged_after_the_depot_closes(): void
    {
        $this->company->update(['depot_closes_at' => now()->addMinutes(30)->format('H:i')]);
        $orders = collect(range(1, 3))->map(fn () => $this->createOrder(['items_amount' => 5000]));
        Sanctum::actingAs($this->dispatcher);
        foreach ($orders as $order) {
            $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
            $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();
            Sanctum::actingAs($this->courierA->user);
            $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
            Sanctum::actingAs($this->dispatcher);
        }
        // Deux livraisons à 40 minutes d'écart, une reste
        Sanctum::actingAs($this->courierB->user);
        foreach ($orders->take(2) as $i => $order) {
            $this->travel($i === 0 ? 0 : 40)->minutes();
            $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
            $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();
        }

        Sanctum::actingAs($this->dispatcher);
        $response = $this->getJson('/api/v1/couriers/activity')->assertOk();
        $b = collect($response->json('data'))->firstWhere('id', $this->courierB->id);
        $this->assertSame(40, $b['pace']);
        $this->assertSame(now()->addMinutes(40)->format('H:i'), Carbon::parse($b['estimated_end'])->format('H:i'));
        $this->assertTrue($b['late_end']);
        $this->assertContains('Finira après la fermeture du dépôt', $b['flags']);
        $this->assertSame(1, $response->json('totals.late_end'));
    }

    public function test_daily_tracking_follows_the_delivery_day_of_the_parcels(): void
    {
        $today = $this->createOrder(['items_amount' => 5000]);
        $tomorrow = $this->createOrder(['items_amount' => 5000]);
        $tomorrow->forceFill(['delivery_scheduled_date' => now()->addDay()->toDateString()])->save();
        $old = $this->createOrder(['items_amount' => 5000]);
        $old->forceFill(['delivery_scheduled_date' => now()->subDays(3)->toDateString()])->save();
        Sanctum::actingAs($this->dispatcher);
        foreach ([$today, $tomorrow, $old] as $order) {
            $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();
        }

        // Aujourd'hui : seulement le colis du jour ; celui d'avant compté à part, celui de demain absent
        $response = $this->getJson('/api/v1/couriers/activity')->assertOk();
        $b = collect($response->json('data'))->firstWhere('id', $this->courierB->id);
        $this->assertSame([0, 1], [$b['deliveries']['done'], $b['deliveries']['total']]);
        $this->assertSame(1, $b['remaining']);
        $this->assertSame(1, $b['late_before']);
        $this->assertContains("1 colis en retard d'avant", $b['flags']);
        $this->assertSame(1, $response->json('totals.late_before'));

        $missions = collect($this->getJson("/api/v1/couriers/{$this->courierB->id}/activity")->json('data'));
        $this->assertSame([$today->id, $old->id], $missions->sortBy('order_id')->pluck('order_id')->values()->all());
        $this->assertTrue($missions->firstWhere('order_id', $old->id)['from_before']);

        // Demain : ce qui est prévu
        $next = $this->getJson('/api/v1/couriers/activity?date='.now()->addDay()->toDateString())->assertOk();
        $this->assertTrue($next->json('is_future'));
        $nb = collect($next->json('data'))->firstWhere('id', $this->courierB->id);
        $this->assertSame([0, 1], [$nb['deliveries']['done'], $nb['deliveries']['total']]);
        $this->assertSame('planned', $nb['state']);
        $this->assertSame(1, $next->json('totals.active'));
    }

    public function test_distribution_counts_letters_and_flags_missing_receipts_and_delays(): void
    {
        $plis = $this->createOrder();
        $late = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        foreach ([$plis, $late] as $order) {
            $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
            Sanctum::actingAs($this->courierA->user);
            $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
            Sanctum::actingAs($this->dispatcher);
            $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();
        }
        foreach ([$plis, $late] as $order) {
            $order->fresh()->forceFill(['activity' => 'distribution', 'shipment_type' => 'pli', 'shipment_quantity' => 3, 'items_amount' => 0, 'cod_amount' => 0])->save();
        }
        $late->fresh()->forceFill(['internal_due_at' => now()->subHour()])->save();
        // Accusé photographié demandé, livraison faite sans la photo (hors connexion)
        $this->company->update(['activity_options' => ['distribution' => ['proof_photo' => true, 'receipt_label' => true]]]);
        $this->company->update(['offline_courier_actions' => true]);
        Sanctum::actingAs($this->courierB->user);
        foreach (['out_for_delivery', 'delivered'] as $i => $status) {
            $this->postJson('/api/v1/courier/sync', ['id' => "pli-{$i}", 'kind' => 'status', 'order_id' => $plis->id, 'occurred_at' => now()->toIso8601String(),
                'payload' => ['status' => $status, 'received_by_name' => 'Mme Koné']])->assertOk()->assertJsonPath('data.result', 'ok');
        }

        Sanctum::actingAs($this->dispatcher);
        $response = $this->getJson('/api/v1/couriers/activity?activity=distribution')->assertOk();
        $b = collect($response->json('data'))->firstWhere('id', $this->courierB->id);
        $this->assertSame([1, 2, 3, 6], [$b['deliveries']['done'], $b['deliveries']['total'], $b['deliveries']['units_done'], $b['deliveries']['units_total']]);
        $this->assertContains('1 accusé de réception manquant', $b['flags']);
        $this->assertContains('1 course hors délai interne', $b['flags']);
        $this->assertSame([1, 1, 6], [$response->json('totals.missing_receipts'), $response->json('totals.out_of_delay'), $response->json('totals.units_total')]);

        // Activité e-commerce seulement : ce livreur n'y a rien fait
        $this->assertNull(collect($this->getJson('/api/v1/couriers/activity?activity=ecommerce')->json('data'))->firstWhere('id', $this->courierB->id));
    }
}
