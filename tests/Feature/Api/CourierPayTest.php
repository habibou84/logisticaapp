<?php

namespace Tests\Feature\Api;

use App\Enums\PayEvent;
use App\Enums\Role;
use App\Models\Company;
use App\Models\CourierEarning;
use App\Models\IncidentReason;
use App\Models\Order;
use App\Models\PayPlan;
use App\Models\User;
use App\Services\Finance\CourierPay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

class CourierPayTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private CourierPay $pay;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->pay = app(CourierPay::class);
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /**
     * @return list<array{0: int, 1: string}>
     */
    private function earnings(?Order $order = null): array
    {
        return CourierEarning::query()->withoutGlobalScopes()->where('courier_id', $this->courierA->id)
            ->when($order, fn ($q) => $q->where('order_id', $order->id))->orderBy('id')->get()
            ->map(fn ($e) => [$e->amount, $e->detail])->all();
    }

    // ───────────── Moteur ─────────────

    public function test_matching_rules_add_up_and_the_detail_is_stored(): void
    {
        $plan = $this->payPlan([
            ['event' => 'delivery', 'calc' => 'fixed', 'amount' => 500],
            ['event' => 'delivery', 'calc' => 'percent_fee', 'percent' => 20],
            ['event' => 'delivery', 'calc' => 'fixed', 'amount' => 1000, 'conditions' => ['express' => true], 'label' => 'Bonus express'],
        ], $this->courierA);
        $order = $this->createOrder(); // frais 1 500 F

        $this->pay->record($this->courierA, PayEvent::Delivery, $order);

        $this->assertSame([[500, 'Montant fixe'], [300, '20 % de 1 500 F (des frais de livraison)']], $this->earnings());
        $earning = CourierEarning::withoutGlobalScopes()->where('courier_id', $this->courierA->id)->first();
        $this->assertSame($plan->id, $earning->pay_plan_id);
        $this->assertSame($plan->rules[0]->id, $earning->pay_plan_rule_id);
        $this->assertSame('delivery', $earning->type->value);

        $express = $this->createOrder();
        $express->forceFill(['is_express' => true])->save();
        $this->assertSame(1800, $this->pay->expected($this->courierA, PayEvent::Delivery, $express));
    }

    public function test_percent_of_collected_uses_the_expected_amount_before_delivery(): void
    {
        $this->payPlan([['event' => 'delivery', 'calc' => 'percent_collected', 'percent' => 2.5]], $this->courierA);
        $order = $this->createOrder(['items_amount' => 20000]);

        $this->assertSame(500, $this->pay->expected($this->courierA, PayEvent::Delivery, $order));

        $order->forceFill(['collected_amount' => 10000])->save();
        $this->pay->record($this->courierA, PayEvent::Delivery, $order);
        $this->assertSame([[250, '2,5 % de 10 000 F (du montant encaissé)']], $this->earnings());
    }

    public function test_zone_grid_uses_the_zone_then_its_commune_then_the_default(): void
    {
        $this->payPlan([[
            'event' => 'delivery', 'calc' => 'zone_grid', 'amount' => 600,
            'zone_amounts' => [$this->cocody->id => 800, $this->yopougon->id => 1200],
        ]], $this->courierA);
        $this->rule($this->grid, $this->cocody, $this->angre, 1000);
        $this->rule($this->grid, $this->cocody, $this->plateau, 1000);

        $this->assertSame(1200, $this->pay->expected($this->courierA, PayEvent::Delivery, $this->createOrder()));
        $this->assertSame(800, $this->pay->expected($this->courierA, PayEvent::Delivery, $this->createOrder(['delivery_zone_id' => $this->angre->id])));
        $this->assertSame(600, $this->pay->expected($this->courierA, PayEvent::Delivery, $this->createOrder(['delivery_zone_id' => $this->plateau->id])));
    }

    public function test_conditions_on_zone_vehicle_and_fragile(): void
    {
        $this->payPlan([
            ['event' => 'delivery', 'calc' => 'fixed', 'amount' => 500],
            ['event' => 'delivery', 'calc' => 'fixed', 'amount' => 200, 'conditions' => ['zone_ids' => [$this->cocody->id]]],
            ['event' => 'delivery', 'calc' => 'fixed', 'amount' => 300, 'conditions' => ['vehicle_types' => ['voiture']]],
            ['event' => 'delivery', 'calc' => 'fixed', 'amount' => 100, 'conditions' => ['fragile' => true]],
        ], $this->courierA);
        $this->rule($this->grid, $this->cocody, $this->angre, 1000);

        $this->courierA->update(['vehicle_type' => 'moto']);
        $this->assertSame(500, $this->pay->expected($this->courierA, PayEvent::Delivery, $this->createOrder()));
        // Angré est un quartier de Cocody
        $this->assertSame(700, $this->pay->expected($this->courierA, PayEvent::Delivery, $this->createOrder(['delivery_zone_id' => $this->angre->id])));

        $this->courierA->update(['vehicle_type' => 'voiture']);
        $fragile = $this->createOrder();
        $fragile->forceFill(['is_fragile' => true])->save();
        $this->assertSame(900, $this->pay->expected($this->courierA->fresh(), PayEvent::Delivery, $fragile));
    }

    public function test_failed_attempt_depends_on_the_reason_and_is_paid_once_a_day(): void
    {
        $absent = IncidentReason::where('code', 'absent')->value('id') ?? IncidentReason::first()->id;
        $other = IncidentReason::where('id', '!=', $absent)->value('id');
        $this->payPlan([
            ['event' => 'failed_attempt', 'calc' => 'fixed', 'amount' => 200, 'conditions' => ['incident_reason_ids' => [$absent]]],
        ], $this->courierA);

        $order = $this->createOrder();
        $order->forceFill(['last_incident_reason_id' => $other])->save();
        $this->pay->record($this->courierA, PayEvent::FailedAttempt, $order);
        $this->assertSame([], $this->earnings());

        $order->forceFill(['last_incident_reason_id' => $absent])->save();
        $this->pay->record($this->courierA, PayEvent::FailedAttempt, $order);
        $this->pay->record($this->courierA, PayEvent::FailedAttempt, $order);
        $this->assertSame([[200, 'Montant fixe']], $this->earnings());

        $this->travel(1)->days();
        $this->pay->record($this->courierA, PayEvent::FailedAttempt, $order);
        $this->assertCount(2, $this->earnings());
    }

    public function test_pickup_per_visit_pays_the_extra_amount_for_the_next_parcels(): void
    {
        $this->payPlan(['pickup' => 500], $this->courierA, ['pickup_mode' => PayPlan::PICKUP_PER_VISIT, 'pickup_extra_parcel_amount' => 100]);
        [$first, $second] = [$this->createOrder(), $this->createOrder()];

        $this->pay->record($this->courierA, PayEvent::Pickup, $first);
        $this->assertSame(100, $this->pay->expected($this->courierA, PayEvent::Pickup, $second));
        $this->pay->record($this->courierA, PayEvent::Pickup, $second);

        $this->assertSame([500, 100], array_column($this->earnings(), 0));

        // Nouveau passage plus tard : de nouveau le montant plein
        $this->travel(CourierPay::VISIT_WINDOW_HOURS + 1)->hours();
        $this->assertSame(500, $this->pay->expected($this->courierA, PayEvent::Pickup, $this->createOrder()));
    }

    public function test_min_and_max_per_step(): void
    {
        $this->payPlan([['event' => 'delivery', 'calc' => 'percent_fee', 'percent' => 50]], $this->courierA, ['min_amount' => 1000, 'max_amount' => 2000]);

        $this->pay->record($this->courierA, PayEvent::Delivery, $this->createOrder()); // 750 → minimum 1 000
        $this->assertSame([[750, '50 % de 1 500 F (des frais de livraison)'], [250, 'Complément jusqu\'au minimum de 1 000 F']], $this->earnings());

        $this->rule($this->grid, $this->cocody, $this->plateau, 6000);
        $this->assertSame(2000, $this->pay->expected($this->courierA, PayEvent::Delivery, $this->createOrder(['delivery_zone_id' => $this->plateau->id])));
    }

    public function test_shipping_falls_back_to_delivery_rules(): void
    {
        $plan = $this->payPlan(['delivery' => 700], $this->courierA);
        $order = $this->createOrder();

        $this->assertSame(700, $this->pay->expected($this->courierA, PayEvent::Shipping, $order));

        $plan->rules()->create(['event' => 'shipping', 'calc' => 'fixed', 'amount' => 400]);
        $this->assertSame(400, $this->pay->expected($this->courierA->fresh(), PayEvent::Shipping, $order));
    }

    public function test_couriers_without_a_plan_use_the_default_plan(): void
    {
        $this->payPlan(['delivery' => 600]);
        $this->payPlan(['delivery' => 900], $this->courierB);
        $order = $this->createOrder();

        $this->assertSame(600, $this->pay->expected($this->courierA, PayEvent::Delivery, $order));
        $this->assertSame(900, $this->pay->expected($this->courierB, PayEvent::Delivery, $order));
    }

    public function test_failed_delivery_pays_the_delivery_courier_through_the_workflow(): void
    {
        $this->payPlan(['pickup' => 300, 'failed_attempt' => 200]);
        $order = $this->createOrder();

        $this->as($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertSuccessful();
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertSuccessful();
        $this->as($this->courierA->user)->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();

        // Gain prévu affiché au livreur sur sa mission
        $this->as($this->courierB->user)->getJson('/api/v1/courier/missions')->assertJsonPath('data.0.gain', null);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => 'delivery_failed', 'incident_reason_id' => IncidentReason::where('code', 'refused')->value('id'),
        ])->assertOk();

        $this->assertSame([['failed_attempt', 200]], CourierEarning::withoutGlobalScopes()->where('courier_id', $this->courierB->id)
            ->get()->map(fn ($e) => [$e->type->value, $e->amount])->all());
        $this->getJson('/api/v1/courier/missions?history=1')->assertJsonPath('data.0.gain', 200);
        $this->getJson('/api/v1/courier/wallet')->assertJsonPath('data.earned_today', 200)
            ->assertJsonPath('data.earnings.0.detail', 'Montant fixe');
    }

    public function test_missions_show_the_expected_gain(): void
    {
        $this->payPlan(['pickup' => 300]);
        $order = $this->createOrder();
        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);

        $this->as($this->courierA->user)->getJson('/api/v1/courier/missions')->assertJsonPath('data.0.gain', 300);
    }

    // ───────────── API des plans ─────────────

    public function test_admin_creates_a_plan_from_a_template_and_edits_its_rules(): void
    {
        $this->payPlan(['delivery' => 500]);
        $this->as($this->admin);

        $this->getJson('/api/v1/pay-plans')->assertOk()
            ->assertJsonCount(6, 'meta.templates')
            ->assertJsonPath('data.0.is_default', true);

        $id = $this->postJson('/api/v1/pay-plans', ['name' => 'Motos', 'template' => 'percent'])->assertCreated()
            ->assertJsonPath('data.pickup_mode', 'per_visit')
            ->assertJsonPath('data.rules.1.calc', 'percent_fee')
            ->json('data.id');

        $this->patchJson("/api/v1/pay-plans/{$id}", [
            'min_amount' => null,
            'rules' => [
                ['event' => 'delivery', 'calc' => 'zone_grid', 'amount' => 700, 'zone_amounts' => [$this->yopougon->id => 1000]],
                ['event' => 'delivery', 'calc' => 'fixed', 'amount' => 300, 'conditions' => ['express' => true, 'fragile' => false, 'zone_ids' => []]],
            ],
        ])->assertOk()
            ->assertJsonCount(2, 'data.rules')
            ->assertJsonPath('data.rules.0.zone_amounts.'.$this->yopougon->id, 1000)
            ->assertJsonPath('data.rules.1.conditions', ['express' => true])
            ->assertJsonPath('data.min_amount', null);

        $this->patchJson("/api/v1/pay-plans/{$id}", ['rules' => [['event' => 'delivery', 'calc' => 'percent_fee']]])
            ->assertJsonValidationErrors('rules.0.percent');
        $this->patchJson("/api/v1/pay-plans/{$id}", ['rules' => [['event' => 'delivery', 'calc' => 'zone_grid', 'zone_amounts' => [999999 => 10]]]])
            ->assertJsonValidationErrors('rules.0.zone_amounts');
    }

    public function test_percent_is_only_checked_for_percent_rules(): void
    {
        $plan = $this->payPlan(['delivery' => 500]);
        $this->as($this->admin);

        // Pourcentage resté dans le formulaire d'une règle à montant fixe : ignoré
        $this->patchJson("/api/v1/pay-plans/{$plan->id}", ['rules' => [
            ['event' => 'pickup', 'calc' => 'fixed', 'amount' => 300, 'percent' => 500, 'zone_amounts' => ['x' => 'y']],
        ]])->assertOk()->assertJsonPath('data.rules.0.percent', null)->assertJsonPath('data.rules.0.amount', 300);

        $this->patchJson("/api/v1/pay-plans/{$plan->id}", ['rules' => [
            ['event' => 'pickup', 'calc' => 'fixed', 'amount' => 300],
            ['event' => 'delivery', 'calc' => 'percent_fee', 'percent' => 150],
        ]])->assertJsonValidationErrors(['rules.1.percent' => 'pourcentage'])
            ->assertJsonMissingValidationErrors('rules.0.percent');
    }

    public function test_default_plan_switch_and_deletion(): void
    {
        $default = $this->payPlan(['delivery' => 500]);
        $other = PayPlan::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Autre']);
        $this->courierA->update(['pay_plan_id' => $other->id]);
        $this->as($this->admin);

        $this->deleteJson("/api/v1/pay-plans/{$default->id}")->assertJsonValidationErrors('plan');
        $this->patchJson("/api/v1/pay-plans/{$default->id}", ['is_default' => false])->assertJsonValidationErrors('is_default');

        $this->patchJson("/api/v1/pay-plans/{$other->id}", ['is_default' => true])->assertOk();
        $this->assertFalse($default->fresh()->is_default);

        $this->deleteJson("/api/v1/pay-plans/{$default->id}")->assertNoContent();
        $this->deleteJson("/api/v1/pay-plans/{$other->id}")->assertJsonValidationErrors('plan');
        $this->assertSame($other->id, $this->courierA->fresh()->pay_plan_id);
    }

    public function test_personal_plan_copies_the_current_plan_and_is_assigned(): void
    {
        $this->payPlan(['delivery' => 500, 'pickup' => 200]);
        $this->as($this->admin);

        $plan = $this->postJson('/api/v1/pay-plans', ['courier_id' => $this->courierA->id])->assertCreated()
            ->assertJsonPath('data.personal', true)
            ->assertJsonPath('data.name', 'Plan de Koffi Ramasseur')
            ->assertJsonCount(2, 'data.rules')
            ->json('data.id');
        $this->assertSame($plan, $this->courierA->fresh()->pay_plan_id);

        // Déjà personnalisé : même plan
        $this->postJson('/api/v1/pay-plans', ['courier_id' => $this->courierA->id])->assertJsonPath('data.id', $plan);

        // Le plan personnel de A ne peut pas être attribué à B
        $this->patchJson("/api/v1/couriers/{$this->courierB->id}", ['pay_plan_id' => $plan])->assertJsonValidationErrors('pay_plan_id');
        $this->getJson("/api/v1/couriers/{$this->courierA->id}")->assertJsonPath('data.pay_plan.personal', true);

        // Suppression : retour au plan par défaut
        $this->deleteJson("/api/v1/pay-plans/{$plan}")->assertNoContent();
        $this->assertNull($this->courierA->fresh()->pay_plan_id);
    }

    public function test_only_settings_managers_change_pay(): void
    {
        $plan = $this->payPlan(['delivery' => 500]);

        $this->as($this->dispatcher)->getJson('/api/v1/pay-plans')->assertForbidden();
        $this->as($this->admin)->patchJson("/api/v1/couriers/{$this->courierA->id}", ['pay_plan_id' => $plan->id])->assertOk();
        $this->as($this->userWithRole(Role::Dispatcher))->patchJson("/api/v1/couriers/{$this->courierA->id}", ['pay_plan_id' => null]);
        $this->assertSame($plan->id, $this->courierA->fresh()->pay_plan_id);
        $hr = $this->userWithRole(Role::Admin, [], Company::factory()->create());
        $this->as($hr)->getJson("/api/v1/pay-plans/{$plan->id}")->assertNotFound();
    }
}
