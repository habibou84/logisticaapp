<?php

namespace Tests\Feature\Api;

use App\Enums\PayEvent;
use App\Models\CourierEarning;
use App\Models\CourierPayout;
use App\Models\IncidentReason;
use App\Models\Order;
use App\Models\User;
use App\Services\Finance\CourierPay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

class PayBonusSimulatorTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00')); // mercredi
        $this->buildWorld();
        $this->courierA->forceFill(['created_at' => '2026-01-01 08:00'])->save();
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /**
     * Ramassage et livraison (ou échec) par le livreur A.
     */
    private function course(?Order $order = null, bool $fail = false): Order
    {
        $order ??= $this->createOrder();
        $this->as($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertSuccessful();
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierA->id])->assertSuccessful();
        $this->as($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", $fail
            ? ['status' => 'delivery_failed', 'incident_reason_id' => IncidentReason::where('code', 'refused')->value('id')]
            : ['status' => 'delivered'])->assertOk();

        return $order;
    }

    // ───────────── Jours et heures ─────────────

    public function test_day_and_time_conditions_use_the_company_timezone(): void
    {
        $plan = $this->payPlan([
            ['event' => 'delivery', 'calc' => 'fixed', 'amount' => 500],
            ['event' => 'delivery', 'calc' => 'fixed', 'amount' => 200, 'label' => 'Week-end', 'conditions' => ['days' => [6, 7]]],
            ['event' => 'delivery', 'calc' => 'fixed', 'amount' => 300, 'label' => 'Nuit', 'conditions' => ['time_from' => '22:00', 'time_to' => '06:00']],
        ], $this->courierA);
        $order = $this->createOrder();
        // Nouvelle instance à chaque calcul, comme à chaque requête (le fuseau est mis en cache)
        $total = fn (string $at) => array_sum(array_column(app(CourierPay::class)->lines($plan, PayEvent::Delivery, $order, $this->courierA, at: CarbonImmutable::parse($at)), 'amount'));

        $this->assertSame(500, $total('2026-10-21 12:00'));   // mercredi midi
        $this->assertSame(700, $total('2026-10-24 12:00'));   // samedi
        $this->assertSame(1000, $total('2026-10-25 23:30'));  // dimanche soir : week-end + nuit
        $this->assertSame(1000, $total('2026-10-24 05:59'));  // samedi avant 6 h
        $this->assertSame(800, $total('2026-10-22 23:00'));   // jeudi soir : nuit seulement
        $this->assertSame(500, $total('2026-10-21 06:00'));   // fin de plage exclue

        // Fuseau de l'entreprise : 21:30 UTC = 23:30 à Paris (heure d'été)
        $this->company->update(['timezone' => 'Europe/Paris']);
        $this->assertSame(800, $total('2026-07-01 21:30'));
    }

    public function test_day_and_time_conditions_apply_to_recorded_earnings(): void
    {
        $this->payPlan(['delivery' => 500, ['event' => 'delivery', 'calc' => 'fixed', 'amount' => 250, 'conditions' => ['days' => [3]]]], $this->courierA);

        $this->course();

        $this->assertSame([500, 250], CourierEarning::withoutGlobalScopes()->where('type', 'delivery')->orderBy('id')->pluck('amount')->all());
    }

    // ───────────── Primes d'objectifs ─────────────

    public function test_bonuses_pay_the_highest_tier_per_metric_at_period_end(): void
    {
        $this->payPlan([], $this->courierA, ['pay_period' => 'weekly']);
        $plan = $this->courierA->fresh()->payPlan;
        $plan->bonuses()->createMany([
            ['metric' => 'deliveries', 'threshold' => 2, 'amount' => 1000],
            ['metric' => 'deliveries', 'threshold' => 3, 'amount' => 2500],
            ['metric' => 'deliveries', 'threshold' => 10, 'amount' => 9000],
            ['metric' => 'success_rate', 'threshold' => 70, 'min_count' => 4, 'amount' => 500],
            ['metric' => 'worked_days', 'threshold' => 2, 'amount' => 700],
        ]);

        $this->course();
        $this->course();
        $this->course(fail: true);

        // Progression visible par le livreur : 2 livraisons, 3 tentatives (taux pas encore pris en compte)
        $objectives = collect($this->as($this->courierA->user)->getJson('/api/v1/courier/wallet')->json('data.pay.objectives'))->keyBy('metric');
        $this->assertSame([2, 1000], [$objectives['deliveries']['value'], $objectives['deliveries']['earned']]);
        $this->assertSame([3, 0], [$objectives['success_rate']['attempts'], $objectives['success_rate']['earned']]);

        $this->course();  // 3 livraisons sur 4 tentatives : 75 %

        $this->artisan('payroll:close', ['--date' => '2026-10-25'])->assertSuccessful();
        $payout = CourierPayout::withoutGlobalScopes()->sole();
        $bonuses = $payout->earnings()->where('type', 'bonus')->orderBy('amount')->get();
        $this->assertSame([500, 2500], $bonuses->pluck('amount')->all());
        $this->assertSame('Objectif 3 livraisons réussies atteint : 3', $bonuses[1]->detail);
        $this->assertSame('2026-10-19', $bonuses[1]->period_start->toDateString());

        // Une seule fois par période
        $this->as($this->admin)->postJson("/api/v1/finance/courier-payouts/{$payout->id}/cancel")->assertOk();
        $this->artisan('payroll:close', ['--date' => '2026-10-25']);
        $this->assertSame(2, CourierEarning::withoutGlobalScopes()->where('type', 'bonus')->count());
    }

    public function test_plan_validation_for_bonuses_and_hours(): void
    {
        $plan = $this->payPlan([]);
        $this->as($this->admin);

        $this->patchJson("/api/v1/pay-plans/{$plan->id}", ['bonuses' => [['metric' => 'deliveries', 'threshold' => 100, 'amount' => 5000]]])
            ->assertJsonValidationErrors('pay_period');
        $this->patchJson("/api/v1/pay-plans/{$plan->id}", ['pay_period' => 'monthly', 'bonuses' => [['metric' => 'success_rate', 'threshold' => 120, 'amount' => 5000]]])
            ->assertJsonValidationErrors('bonuses.0.threshold');
        $this->patchJson("/api/v1/pay-plans/{$plan->id}", ['rules' => [['event' => 'delivery', 'calc' => 'fixed', 'amount' => 100, 'conditions' => ['time_from' => '22:00']]]])
            ->assertJsonValidationErrors('rules.0.conditions.time_to');

        $this->patchJson("/api/v1/pay-plans/{$plan->id}", [
            'pay_period' => 'monthly',
            'bonuses' => [['metric' => 'success_rate', 'threshold' => 90, 'min_count' => 30, 'amount' => 5000], ['metric' => 'deliveries', 'threshold' => 100, 'min_count' => 9, 'amount' => 5000]],
            'rules' => [['event' => 'delivery', 'calc' => 'fixed', 'amount' => 100, 'conditions' => ['days' => [6, 7], 'time_from' => '22:00', 'time_to' => '06:00']]],
        ])->assertOk()
            ->assertJsonPath('data.bonuses.0.metric', 'deliveries')
            ->assertJsonPath('data.bonuses.0.min_count', null)
            ->assertJsonPath('data.bonuses.1.min_count', 30)
            ->assertJsonPath('data.rules.0.conditions.days', [6, 7]);

        // Copie et modèle « Mixte » : les primes suivent
        $this->postJson('/api/v1/pay-plans', ['name' => 'Copie', 'copy_from' => $plan->id])->assertJsonCount(2, 'data.bonuses');
        $this->postJson('/api/v1/pay-plans', ['name' => 'Mixte', 'template' => 'mixed'])->assertJsonCount(3, 'data.bonuses');
    }

    // ───────────── Simulateur ─────────────

    public function test_simulate_a_scenario_with_unsaved_settings(): void
    {
        $this->as($this->admin)->postJson('/api/v1/pay-plans/simulate', [
            'mode' => 'scenario',
            'min_amount' => 1000,
            'rules' => [
                ['event' => 'delivery', 'calc' => 'percent_fee', 'percent' => 30],
                ['event' => 'delivery', 'calc' => 'fixed', 'amount' => 200, 'label' => 'Week-end', 'conditions' => ['days' => [6, 7]]],
            ],
            'scenario' => ['event' => 'delivery', 'zone_id' => $this->cocody->id, 'fees' => 2000, 'at' => '2026-10-24 15:00'],
        ])->assertOk()
            ->assertJsonPath('data.total', 1000)
            ->assertJsonPath('data.lines.0.detail', '30 % de 2 000 F (des frais de livraison)')
            ->assertJsonPath('data.lines.1.label', 'Week-end')
            ->assertJsonPath('data.lines.2.label', 'Minimum par course')
            ->assertJsonPath('data.lines.2.amount', 200);

        $this->as($this->dispatcher)->postJson('/api/v1/pay-plans/simulate', ['mode' => 'scenario'])->assertForbidden();
    }

    public function test_replay_a_past_period_and_compare_with_actual_pay(): void
    {
        $this->payPlan(['pickup' => 300, 'delivery' => 500]);
        $this->course();
        $this->course();
        $this->course(fail: true);

        $data = $this->as($this->admin)->postJson('/api/v1/pay-plans/simulate', [
            'mode' => 'replay',
            'date' => '2026-10-21',
            'pay_period' => 'weekly',
            'base_salary' => 10000,
            'pickup_mode' => 'per_visit',
            'pickup_extra_parcel_amount' => 50,
            'rules' => [
                ['event' => 'pickup', 'calc' => 'fixed', 'amount' => 400],
                ['event' => 'delivery', 'calc' => 'fixed', 'amount' => 700],
                ['event' => 'failed_attempt', 'calc' => 'fixed', 'amount' => 150],
            ],
            'bonuses' => [['metric' => 'deliveries', 'threshold' => 2, 'amount' => 1000]],
        ])->assertOk()
            ->assertJsonPath('data.period_start', '2026-10-19')
            ->assertJsonPath('data.period_end', '2026-10-21')
            ->json('data');

        $row = collect($data['couriers'])->firstWhere('courier_id', $this->courierA->id);
        // 3 ramassages chez le même marchand (400 + 50 + 50), 2 livraisons (1 400), 1 échec (150)
        $this->assertSame(2050, $row['courses']);
        $this->assertSame([['label' => 'Prime 2 livraisons réussies', 'amount' => 1000]], $row['bonuses']);
        $this->assertSame(13050, $row['simulated']);
        // Réel : 3 × 300 + 2 × 500 (pas de tentative ratée dans le plan par défaut)
        $this->assertSame(1900, $row['actual']);
        $this->assertSame(['pickup' => 3, 'delivery' => 2, 'failed_attempt' => 1], $row['counts']);

        $this->postJson('/api/v1/pay-plans/simulate', ['mode' => 'replay', 'date' => '2030-01-01'])->assertJsonValidationErrors('date');
    }
}
