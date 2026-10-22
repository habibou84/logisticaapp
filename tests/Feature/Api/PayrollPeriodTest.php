<?php

namespace Tests\Feature\Api;

use App\Enums\EarningType;
use App\Enums\Role;
use App\Models\CourierEarning;
use App\Models\CourierPayout;
use App\Models\Order;
use App\Models\PayPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

class PayrollPeriodTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));
        $this->buildWorld();
        $this->cashier = $this->userWithRole(Role::Cashier);
        // Livreurs « embauchés » avant la période
        $this->courierA->forceFill(['created_at' => '2026-01-01 08:00'])->save();
        $this->courierB->forceFill(['created_at' => '2026-01-01 08:00'])->save();
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    private function earn(int $amount, string $at, EarningType $type = EarningType::Delivery): CourierEarning
    {
        $earning = CourierEarning::create([
            'company_id' => $this->company->id, 'courier_id' => $this->courierA->id, 'type' => $type, 'amount' => $amount, 'description' => 'Test',
        ]);
        DB::table('courier_earnings')->where('id', $earning->id)->update(['created_at' => $at]);

        return $earning;
    }

    private function deliverWithCash(int $items): Order
    {
        $order = $this->createOrder(['items_amount' => $items]);
        $this->as($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierA->id]);
        $this->as($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();

        return $order;
    }

    public function test_pay_periods(): void
    {
        $plan = new PayPlan;
        $day = CarbonImmutable::parse('2026-10-22'); // jeudi

        $plan->pay_period = 'weekly';
        $this->assertSame(['2026-10-19', '2026-10-25'], array_map(fn ($d) => $d->toDateString(), $plan->periodContaining($day)));
        $plan->pay_period = 'biweekly';
        $this->assertSame(['2026-10-16', '2026-10-31'], array_map(fn ($d) => $d->toDateString(), $plan->periodContaining($day)));
        $this->assertSame(['2026-10-01', '2026-10-15'], array_map(fn ($d) => $d->toDateString(), $plan->periodContaining($day->setDay(3))));
        $plan->pay_period = 'monthly';
        $this->assertSame(['2026-02-01', '2026-02-28'], array_map(fn ($d) => $d->toDateString(), $plan->periodContaining($day->setDate(2026, 2, 10))));
        $plan->pay_period = null;
        $this->assertNull($plan->periodContaining($day));
    }

    public function test_month_end_prepares_one_payslip_with_salary_and_period_earnings(): void
    {
        $this->payPlan(['delivery' => 300], $this->courierA, ['base_salary' => 60000, 'pay_period' => 'monthly']);
        $this->earn(300, '2026-09-30 18:00');
        $this->earn(500, '2026-10-02 18:00');
        $this->earn(700, '2026-11-01 09:00'); // période suivante

        $this->as($this->courierA->user)->getJson('/api/v1/courier/wallet')
            ->assertJsonPath('data.pay', ['base_salary' => 60000, 'period_label' => 'Chaque mois', 'next_payslip' => '2026-11-01', 'objectives' => []]);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 00:20'));
        $this->artisan('payroll:close')->expectsOutput('1 fiche(s) de paie préparée(s).')->assertSuccessful();

        $payout = CourierPayout::withoutGlobalScopes()->sole();
        $this->assertTrue($payout->automatic);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$payout->period_start->toDateString(), $payout->period_end->toDateString()]);
        $this->assertSame(60800, $payout->amount);
        $this->assertSame(1, $payout->earnings()->where('type', 'salary')->count());
        $this->assertSame(700, (int) CourierEarning::withoutGlobalScopes()->whereNull('payout_id')->sum('amount'));

        $this->as($this->courierA->user)->getJson('/api/v1/courier/wallet')
            ->assertJsonPath('data.pending_payslips.0.amount', 60800)
            ->assertJsonPath('data.pending_payslips.0.period_end', '2026-10-31');

        // Deuxième passage, ou fiche annulée : rien n'est recréé
        $this->as($this->cashier)->postJson("/api/v1/finance/courier-payouts/{$payout->id}/cancel")->assertOk();
        $this->artisan('payroll:close')->expectsOutput('0 fiche(s) de paie préparée(s).');
        $this->assertSame(1, CourierPayout::withoutGlobalScopes()->count());
        $this->assertSame(1, CourierEarning::withoutGlobalScopes()->where('type', 'salary')->count());
    }

    public function test_salary_is_prorated_for_a_courier_hired_during_the_period(): void
    {
        $this->courierA->forceFill(['created_at' => '2026-10-17 09:00'])->save();
        $this->payPlan([], $this->courierA, ['base_salary' => 31000, 'pay_period' => 'monthly']);

        $this->artisan('payroll:close', ['--date' => '2026-10-31'])->assertSuccessful();

        $salary = CourierEarning::withoutGlobalScopes()->where('type', 'salary')->sole();
        $this->assertSame(15000, $salary->amount);
        $this->assertSame('31 000 F × 15/31 jours (arrivée en cours de période)', $salary->detail);
    }

    public function test_couriers_paid_on_demand_get_no_automatic_payslip(): void
    {
        $this->payPlan(['delivery' => 300], $this->courierA);
        $this->earn(300, '2026-10-02 18:00');

        $this->artisan('payroll:close', ['--date' => '2026-10-31'])->expectsOutput('0 fiche(s) de paie préparée(s).');
    }

    public function test_deductions_are_capped_and_the_rest_is_carried_over(): void
    {
        $this->payPlan([], $this->courierA, ['deduction_cap_percent' => 30, 'pay_period' => 'weekly']);
        $this->earn(10000, '2026-10-13 10:00');
        $this->earn(-5000, '2026-10-14 10:00', EarningType::LostParcel);

        $this->artisan('payroll:close', ['--date' => '2026-10-19'])->assertSuccessful();
        $first = CourierPayout::withoutGlobalScopes()->sole();
        // 10 000 − 3 000 (30 %) : 2 000 reportés
        $this->assertSame(7000, $first->amount);
        $this->assertSame('Retenues limitées à 30 % des gains (10 000 F) : 2 000 F reportés', $first->earnings()->where('type', 'carryover')->value('detail'));

        $pending = CourierEarning::withoutGlobalScopes()->whereNull('payout_id')->sole();
        $this->assertSame([-2000, 'Retenue reportée de '.$first->reference], [$pending->amount, $pending->description]);

        // Semaine suivante : la retenue reportée passe, dans la limite du plafond
        $this->earn(10000, '2026-10-21 10:00');
        $this->artisan('payroll:close', ['--date' => '2026-10-26'])->assertSuccessful();
        $this->assertSame(8000, CourierPayout::withoutGlobalScopes()->latest('id')->value('amount'));
    }

    public function test_cancelling_a_capped_payslip_puts_everything_back(): void
    {
        $this->payPlan([], $this->courierA, ['deduction_cap_percent' => 10]);
        $this->earn(10000, '2026-10-13 10:00');
        $this->earn(-5000, '2026-10-14 10:00', EarningType::LostParcel);

        $this->as($this->cashier);
        $id = $this->postJson('/api/v1/finance/courier-payouts', ['courier_id' => $this->courierA->id])->assertCreated()
            ->assertJsonPath('data.amount', 9000)->json('data.id');
        $this->postJson("/api/v1/finance/courier-payouts/{$id}/cancel")->assertOk();

        // Le report (+4 000 / −4 000) revient en attente avec le reste : la retenue entière compte de nouveau
        $this->assertSame([10000, -5000, 4000, -4000], CourierEarning::withoutGlobalScopes()->orderBy('id')->pluck('amount')->all());
        $this->assertSame(0, CourierEarning::withoutGlobalScopes()->whereNotNull('payout_id')->count());
        $this->assertSame(9000, $this->postJson('/api/v1/finance/courier-payouts', ['courier_id' => $this->courierA->id])->json('data.amount'));
    }

    public function test_only_deductions_cannot_make_a_payslip_when_capped(): void
    {
        $this->payPlan([], $this->courierA, ['deduction_cap_percent' => 30]);
        $this->earn(-5000, '2026-10-14 10:00', EarningType::LostParcel);

        $this->as($this->cashier)->postJson('/api/v1/finance/courier-payouts', ['courier_id' => $this->courierA->id])
            ->assertJsonValidationErrors('courier_id');
    }

    public function test_courier_can_keep_his_pay_on_the_cash_he_collected(): void
    {
        $this->payPlan(['delivery' => 1000], $this->courierA);
        $this->deliverWithCash(10000);
        $this->earn(2000, '2026-10-19 10:00', EarningType::Adjustment);

        $this->as($this->cashier);
        $id = $this->postJson('/api/v1/finance/courier-payouts', ['courier_id' => $this->courierA->id])
            ->assertJsonPath('data.amount', 3000)->json('data.id');
        $this->postJson("/api/v1/finance/courier-payouts/{$id}/pay", ['method' => 'compensation'])->assertOk()
            ->assertJsonPath('data.compensated', true)
            ->assertJsonPath('data.method_label', 'gardé sur l\'encaissé');

        // 10 000 encaissés − 3 000 de paie gardée
        $this->as($this->courierA->user)->getJson('/api/v1/courier/wallet')
            ->assertJsonPath('data.cash_in_hand', 7000)
            ->assertJsonPath('data.balance.pay_kept', 3000)
            ->assertJsonPath('data.advances.0.pay_kept', true);

        // Le versement règle la paie gardée
        $this->as($this->cashier)->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierA->id, 'amount_received' => 7000])
            ->assertCreated()->assertJsonPath('data.difference', 0);
    }

    public function test_compensation_needs_enough_cash_in_hand(): void
    {
        $this->payPlan(['delivery' => 1000], $this->courierA);
        $this->earn(50000, '2026-10-19 10:00', EarningType::Adjustment);

        $this->as($this->cashier);
        $id = $this->postJson('/api/v1/finance/courier-payouts', ['courier_id' => $this->courierA->id])->json('data.id');
        $this->postJson("/api/v1/finance/courier-payouts/{$id}/pay", ['method' => 'compensation'])->assertJsonValidationErrors('method');
        $this->postJson("/api/v1/finance/courier-payouts/{$id}/pay", ['method' => 'cheque'])->assertJsonValidationErrors('method');
        $this->postJson("/api/v1/finance/courier-payouts/{$id}/pay", ['method' => 'wave'])->assertOk();
    }

    public function test_salary_needs_a_pay_period(): void
    {
        $plan = $this->payPlan([]);
        $this->as($this->admin);

        $this->patchJson("/api/v1/pay-plans/{$plan->id}", ['base_salary' => 80000])->assertJsonValidationErrors('pay_period');
        $this->patchJson("/api/v1/pay-plans/{$plan->id}", ['base_salary' => 80000, 'pay_period' => 'monthly', 'deduction_cap_percent' => 25])
            ->assertOk()
            ->assertJsonPath('data.pay_period_label', 'Chaque mois')
            ->assertJsonPath('data.deduction_cap_percent', 25);

        $this->postJson('/api/v1/pay-plans', ['name' => 'Salariés', 'template' => 'salaried'])->assertCreated()
            ->assertJsonPath('data.base_salary', 100000)
            ->assertJsonPath('data.pay_period', 'monthly')
            ->assertJsonCount(0, 'data.rules');
    }
}
