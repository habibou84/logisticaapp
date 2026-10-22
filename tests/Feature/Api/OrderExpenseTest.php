<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\OrderExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Autres frais d'une course (transport, emballage, stationnement…).
 */
class OrderExpenseTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $cashier;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->cashier = $this->userWithRole(Role::Cashier);

        $this->order = $this->createOrder(['items_amount' => 10000]);
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$this->order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    private function addExpense(array $data)
    {
        return $this->postJson("/api/v1/orders/{$this->order->id}/expenses", $data);
    }

    public function test_courier_declares_fees_billed_to_the_merchant(): void
    {
        $this->as($this->courierA->user)->addExpense([
            'type' => 'transport', 'label' => 'Taxi pour colis volumineux', 'amount' => 2000,
            'paid_by' => 'company', 'billed_to' => 'company', // ignorés pour un livreur
        ])->assertCreated()
            ->assertJsonPath('data.paid_by', 'courier')
            ->assertJsonPath('data.billed_to', 'merchant')
            ->assertJsonPath('data.description', 'Transport (taxi, moto-taxi…) : Taxi pour colis volumineux');

        $entry = MerchantLedgerEntry::where('order_id', $this->order->id)->where('type', 'other_fee')->sole();
        $this->assertSame(-2000, $entry->amount);

        // Remboursé sur son versement, visible dans le point du marchand et notifié
        $this->as($this->courierA->user)->getJson('/api/v1/courier/wallet')->assertJsonPath('data.cash_in_hand', -2000);
        $this->as($this->merchantUser)->getJson('/api/v1/reports/summary')
            ->assertJsonPath('data.amounts.other_fees', 2000)
            ->assertJsonPath('data.amounts.net_to_merchant', -2000);
        $this->assertSame('expense', $this->merchantUser->notifications()->latest()->first()->data['kind']);
        $this->getJson("/api/v1/orders/{$this->order->id}")->assertJsonPath('data.expenses.0.amount', 2000);
    }

    public function test_other_fees_need_a_description_and_merchants_cannot_add_fees(): void
    {
        $this->as($this->courierA->user)->addExpense(['type' => 'other', 'amount' => 500])->assertJsonValidationErrors('label');
        $this->as($this->merchantUser)->addExpense(['type' => 'packaging', 'amount' => 500])->assertForbidden();
        $this->as($this->courierB->user)->addExpense(['type' => 'packaging', 'amount' => 500])->assertForbidden();
    }

    public function test_staff_chooses_who_paid_and_who_bears_the_cost(): void
    {
        // Payé et supporté par l'agence : aucune écriture marchand, invisible pour lui
        $this->as($this->dispatcher)->addExpense([
            'type' => 'parking', 'amount' => 300, 'paid_by' => 'company', 'billed_to' => 'company',
        ])->assertCreated();
        $this->assertSame(0, MerchantLedgerEntry::where('type', 'other_fee')->count());

        // Payé par le livreur de la course, facturé au marchand
        $this->addExpense(['type' => 'packaging', 'amount' => 700])
            ->assertCreated()->assertJsonPath('data.courier_name', $this->courierA->user->name);
        $this->assertSame($this->courierA->id, OrderExpense::latest('id')->first()->courier_id);

        $this->as($this->merchantUser)->getJson("/api/v1/orders/{$this->order->id}")
            ->assertJsonCount(1, 'data.expenses')
            ->assertJsonPath('data.expenses.0.type', 'packaging');
    }

    public function test_cancelling_fees_reverses_the_ledger_until_refunded(): void
    {
        $expense = $this->as($this->courierA->user)->addExpense(['type' => 'transport', 'amount' => 1500])->json('data');

        $this->postJson("/api/v1/orders/{$this->order->id}/expenses/{$expense['id']}/cancel")->assertForbidden();

        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$this->order->id}/expenses/{$expense['id']}/cancel")
            ->assertOk()->assertJsonPath('data.cancelled_at', fn ($v) => $v !== null);
        $this->assertSame(0, (int) MerchantLedgerEntry::where('order_id', $this->order->id)->where('type', 'other_fee')->sum('amount'));
        $this->as($this->courierA->user)->getJson('/api/v1/courier/wallet')->assertJsonPath('data.cash_in_hand', 0);

        // Une fois le livreur remboursé, on passe par un ajustement
        $second = $this->addExpense(['type' => 'transport', 'amount' => 1000])->json('data');
        $this->as($this->cashier)->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierA->id, 'amount_received' => -1000])
            ->assertCreated()->assertJsonPath('data.amount_expected', -1000);
        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$this->order->id}/expenses/{$second['id']}/cancel")
            ->assertUnprocessable();
    }

    public function test_fees_appear_on_the_merchant_statement(): void
    {
        $this->as($this->courierA->user)->addExpense(['type' => 'packaging', 'amount' => 800]);

        $payout = $this->as($this->cashier)->postJson('/api/v1/finance/payouts', ['merchant_id' => $this->merchant->id])
            ->assertCreated()->json('data');
        $this->assertSame(800, $payout['total_other_fees']);
        $this->assertSame(-800, $payout['net_amount']);
    }
}
