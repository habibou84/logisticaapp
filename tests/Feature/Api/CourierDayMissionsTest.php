<?php

namespace Tests\Feature\Api;

use App\Models\BillingPlan;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Application du livreur : livraisons du jour selon l'activité (réglage) — e-commerce le jour même, distribution
 * aussi dans le délai interne ou avant l'échéance du lot, MAD sans limite. Les retards et les jours à venir restent
 * à l'agence (nouvelle date ou annulation), et le livreur ne peut pas les faire.
 */
class CourierDayMissionsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->buildWorld();
    }

    /** Colis ramassé par le livreur A, livraison confiée au livreur B, prévu le jour donné */
    private function pickedUpFor(?string $day, array $attributes = []): Order
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        $order->forceFill(['delivery_scheduled_date' => $day, ...$attributes])->save();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();

        return $order->fresh();
    }

    /** Livraisons visibles dans l'application du livreur B */
    private function visible(): array
    {
        Sanctum::actingAs($this->courierB->user);

        return collect($this->getJson('/api/v1/courier/missions')->assertOk()->json('data'))
            ->where('type', 'delivery')->pluck('order.id')->sort()->values()->all();
    }

    private function sendOut(Order $order)
    {
        Sanctum::actingAs($this->courierB->user);

        return $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery']);
    }

    public function test_ecommerce_courier_only_sees_and_delivers_todays_parcels(): void
    {
        $today = $this->pickedUpFor(now()->toDateString());
        $late = $this->pickedUpFor(now()->subDays(2)->toDateString());
        $later = $this->pickedUpFor(now()->addDay()->toDateString());

        $this->assertSame([$today->id], $this->visible());
        $this->getJson('/api/v1/courier/missions')->assertJsonPath('meta.other_days', 2);

        // Ni le retard ni le colis de demain ne peuvent partir en livraison
        foreach ([$late, $later] as $order) {
            $this->sendOut($order)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'livraisons du jour'));
        }
        $this->sendOut($today)->assertOk();

        // L'agence remet le retard à aujourd'hui : il apparaît et peut partir
        $late->forceFill(['delivery_scheduled_date' => now()->toDateString()])->save();
        $this->assertContains($late->id, $this->visible());
        $this->sendOut($late)->assertOk();
    }

    public function test_distribution_stays_open_within_the_internal_delay_or_until_the_lot_is_due(): void
    {
        $inDelay = $this->pickedUpFor(now()->subDay()->toDateString(), ['activity' => 'distribution', 'internal_due_at' => now()->addHours(5)]);
        $pastDelay = $this->pickedUpFor(now()->subDays(3)->toDateString(), ['activity' => 'distribution', 'internal_due_at' => now()->subHour()]);
        $lot = BillingPlan::create([
            'company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'formula' => 'distribution_lot', 'activity' => 'distribution',
            'delivery_delay' => '48h', 'starts_on' => now()->subWeek()->toDateString(), 'lot_label' => 'Relevés', 'lot_quantity' => 500,
            'lot_price' => 100000, 'lot_due_on' => now()->addDays(3)->toDateString(),
        ]);
        $inLot = $this->pickedUpFor(now()->subDays(4)->toDateString(), ['activity' => 'distribution', 'billing_plan_id' => $lot->id]);
        $tomorrow = $this->pickedUpFor(now()->addDay()->toDateString(), ['activity' => 'distribution', 'internal_due_at' => now()->addDays(2)]);

        $this->assertSame(collect([$inDelay->id, $inLot->id])->sort()->values()->all(), $this->visible());
        $this->sendOut($inDelay)->assertOk();
        $this->sendOut($inLot)->assertOk();
        $this->sendOut($pastDelay)->assertStatus(422);
        // Un jour à venir reste à l'agence, même dans le délai
        $this->sendOut($tomorrow)->assertStatus(422);
    }

    public function test_mad_has_no_date_limit_and_each_rule_can_be_changed(): void
    {
        $mad = $this->pickedUpFor(now()->subDays(2)->toDateString(), ['activity' => 'mad']);
        $late = $this->pickedUpFor(now()->subDays(2)->toDateString());
        $this->assertSame([$mad->id], $this->visible());
        $this->sendOut($mad)->assertOk();

        // L'entreprise lève la limite pour l'e-commerce
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['delivery_day_rules' => ['ecommerce' => 'none']])->assertOk()
            ->assertJsonPath('data.delivery_day_rules.ecommerce', 'none')
            ->assertJsonPath('data.delivery_day_rules.distribution', 'within_delay');
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['delivery_day_rules' => ['ecommerce' => 'toujours']])
            ->assertJsonValidationErrors('delivery_day_rules.ecommerce');
        $this->assertContains($late->id, $this->visible());
        $this->sendOut($late)->assertOk();
    }

    public function test_an_offline_action_is_judged_on_the_day_it_was_done(): void
    {
        $this->company->update(['offline_courier_actions' => true]);
        $yesterday = $this->pickedUpFor(now()->subDay()->toDateString());
        Sanctum::actingAs($this->courierB->user);

        // Partie hier hors connexion, envoyée aujourd'hui : acceptée
        $this->postJson('/api/v1/courier/sync', [
            'id' => 'a1b2c3', 'kind' => 'status', 'order_id' => $yesterday->id, 'occurred_at' => now()->subDay()->setTime(10, 0)->toIso8601String(),
            'payload' => ['status' => 'out_for_delivery'],
        ])->assertOk()->assertJsonPath('data.result', 'ok');
        $this->assertSame('out_for_delivery', $yesterday->fresh()->status->value);

        // Colis prévu aujourd'hui, action datée d'hier : refusée et mise de côté pour le livreur
        $today = $this->pickedUpFor(now()->toDateString());
        Sanctum::actingAs($this->courierB->user);
        $this->postJson('/api/v1/courier/sync', [
            'id' => 'd4e5f6', 'kind' => 'status', 'order_id' => $today->id, 'occurred_at' => now()->subDay()->setTime(10, 0)->toIso8601String(),
            'payload' => ['status' => 'out_for_delivery'],
        ])->assertOk()->assertJsonPath('data.result', 'rejected');
    }
}
