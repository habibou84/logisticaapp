<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\CashCollection;
use App\Models\IncidentReason;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->cashier = $this->userWithRole(Role::Cashier);
        $this->payPlan(['pickup' => 300, 'delivery' => 500, 'return' => 400], $this->courierA);
        $this->payPlan(['pickup' => 300, 'delivery' => 500], $this->courierB);
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    private function move(Order $order, string $status, array $extra = [])
    {
        return $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => $status, ...$extra]);
    }

    /**
     * Ramassée par A, livrée par B.
     */
    private function deliver(Order $order, array $payment = []): Order
    {
        $this->as($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        $this->as($this->courierA->user)->move($order, 'picked_up')->assertOk();
        $this->as($this->courierB->user)->move($order, 'out_for_delivery')->assertOk();
        $this->move($order, 'delivered', $payment)->assertOk();

        return $order->fresh();
    }

    private function ledgerTypes(Order $order): array
    {
        return MerchantLedgerEntry::where('order_id', $order->id)->orderBy('id')->get()
            ->map(fn ($e) => [$e->type->value, $e->amount])->all();
    }

    public function test_delivery_records_cash_ledger_and_commissions(): void
    {
        $order = $this->deliver($this->createOrder(['items_amount' => 10000]));

        $collection = $order->cashCollection;
        $this->assertSame(10000, $collection->amount_collected);
        $this->assertSame($this->courierB->id, $collection->courier_id);
        $this->assertSame('cash', $collection->method->value);
        $this->assertFalse($collection->isSettled());

        $this->assertSame([['cod_credit', 10000], ['delivery_fee', -1500]], $this->ledgerTypes($order));

        $this->assertSame(300, (int) $this->courierA->earnings()->sum('amount'));
        $this->assertSame(500, (int) $this->courierB->earnings()->sum('amount'));
    }

    public function test_fees_paid_by_recipient_are_withheld_from_the_cash(): void
    {
        $order = $this->deliver($this->createOrder(['items_amount' => 15000, 'fee_payer' => 'recipient']));

        $this->assertSame([['cod_credit', 16500], ['delivery_fee', -1500]], $this->ledgerTypes($order));
    }

    public function test_prepaid_order_only_charges_fees(): void
    {
        $order = $this->deliver($this->createOrder(['items_amount' => 0]));

        $this->assertNull($order->cashCollection);
        $this->assertSame([['delivery_fee', -1500]], $this->ledgerTypes($order));
    }

    public function test_mobile_money_paid_to_the_company_is_not_in_courier_hands(): void
    {
        $order = $this->deliver($this->createOrder(), [
            'payment_method' => 'wave', 'received_by_company' => true, 'transaction_ref' => 'WV123',
        ]);

        $this->assertTrue($order->cashCollection->isSettled());
        $this->assertSame('WV123', $order->cashCollection->transaction_ref);

        $this->as($this->cashier)->getJson('/api/v1/finance/cash')
            ->assertJsonPath('totals.cash_in_hands', 0);
    }

    public function test_cash_cannot_be_declared_as_received_by_the_company(): void
    {
        $order = $this->createOrder();
        $this->as($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierA->id]);
        $this->as($this->courierA->user)->move($order, 'picked_up');
        $this->move($order, 'out_for_delivery');

        $this->move($order, 'delivered', ['payment_method' => 'cash', 'received_by_company' => true])
            ->assertJsonValidationErrors('received_by_company');
    }

    public function test_courier_remits_cash_with_a_shortfall_deducted_from_pay(): void
    {
        $this->deliver($this->createOrder(['items_amount' => 10000]));
        $this->deliver($this->createOrder(['items_amount' => 5000]));

        $this->as($this->cashier)->getJson('/api/v1/finance/cash')
            ->assertOk()
            ->assertJsonPath('data.0.courier_id', $this->courierB->id)
            ->assertJsonPath('data.0.cash_in_hand', 15000)
            ->assertJsonPath('data.0.pending_collections', 2);

        $this->postJson('/api/v1/finance/remittances', [
            'courier_id' => $this->courierB->id,
            'amount_received' => 14000,
            'notes' => 'Il manque 1000 F',
        ])->assertCreated()
            ->assertJsonPath('data.amount_expected', 15000)
            ->assertJsonPath('data.difference', -1000)
            ->assertJsonPath('data.received_by', $this->cashier->name);

        $this->assertSame(0, CashCollection::inCourierHands()->count());

        $this->getJson("/api/v1/finance/couriers/{$this->courierB->id}/earnings")
            ->assertJsonPath('summary.unpaid', 0) // 2 livraisons à 500 F - 1000 F de manque
            ->assertJsonPath('summary.cash_in_hand', 0);

        $this->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierB->id, 'amount_received' => 0])
            ->assertJsonPath('message', 'Ce livreur n\'a aucun encaissement à verser.');
    }

    public function test_partial_remittance_of_selected_collections(): void
    {
        $first = $this->deliver($this->createOrder(['items_amount' => 10000]));
        $this->deliver($this->createOrder(['items_amount' => 5000]));

        $this->as($this->cashier)->postJson('/api/v1/finance/remittances', [
            'courier_id' => $this->courierB->id,
            'amount_received' => 10000,
            'collection_ids' => [$first->cashCollection->id],
        ])->assertCreated()->assertJsonPath('data.difference', 0);

        $this->getJson("/api/v1/finance/couriers/{$this->courierB->id}/collections")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount_collected', 5000);
    }

    public function test_payout_only_includes_orders_whose_cash_reached_the_company(): void
    {
        $remitted = $this->deliver($this->createOrder(['items_amount' => 10000]));
        $this->as($this->cashier)->postJson('/api/v1/finance/remittances', [
            'courier_id' => $this->courierB->id, 'amount_received' => 10000,
        ])->assertCreated();
        $pending = $this->deliver($this->createOrder(['items_amount' => 20000]));

        $this->as($this->cashier)->getJson("/api/v1/finance/merchants/{$this->merchant->id}/ledger")
            ->assertJsonPath('summary.available', 8500)
            ->assertJsonPath('summary.pending_cash', 18500)
            ->assertJsonPath('summary.unpaid_total', 27000);

        $payout = $this->postJson('/api/v1/finance/payouts', ['merchant_id' => $this->merchant->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_collected', 10000)
            ->assertJsonPath('data.total_fees', 1500)
            ->assertJsonPath('data.net_amount', 8500)
            ->assertJsonCount(2, 'data.entries')
            ->json('data');

        $this->assertMatchesRegularExpression('/^RV-\d{6}-\d{5}$/', $payout['reference']);
        $this->assertSame(
            [$remitted->id],
            MerchantLedgerEntry::where('payout_id', $payout['id'])->distinct()->pluck('order_id')->all(),
        );
        $this->assertNull(MerchantLedgerEntry::where('order_id', $pending->id)->value('payout_id'));

        $this->postJson("/api/v1/finance/payouts/{$payout['id']}/pay", ['method' => 'wave', 'transaction_ref' => 'WV-999'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.method_label', 'Wave');

        $this->getJson("/api/v1/finance/merchants/{$this->merchant->id}/ledger")
            ->assertJsonPath('summary.available', 0)
            ->assertJsonPath('summary.balance', 18500); // reste la course dont l'argent est chez le livreur

        $this->postJson("/api/v1/finance/payouts/{$payout['id']}/pay", ['method' => 'cash'])
            ->assertUnprocessable();
    }

    public function test_cancelling_a_draft_payout_releases_its_entries(): void
    {
        $this->deliver($this->createOrder(['items_amount' => 10000]));
        $this->as($this->cashier)->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierB->id, 'amount_received' => 10000]);
        $id = $this->postJson('/api/v1/finance/payouts', ['merchant_id' => $this->merchant->id])->json('data.id');

        $this->postJson("/api/v1/finance/payouts/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(0, MerchantLedgerEntry::whereNotNull('payout_id')->count());
        $this->postJson('/api/v1/finance/payouts', ['merchant_id' => $this->merchant->id])->assertCreated();
    }

    public function test_return_fee_follows_company_setting_and_pays_the_courier(): void
    {
        $this->company->update(['return_fee_percent' => 50]);
        $order = $this->createOrder();
        $this->as($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        $this->as($this->courierA->user)->move($order, 'picked_up');
        $this->as($this->courierB->user)->move($order, 'out_for_delivery');
        $this->move($order, 'delivery_failed', ['incident_reason_id' => IncidentReason::where('code', 'refused')->value('id')]);
        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'return', 'courier_id' => $this->courierA->id]);
        $this->as($this->courierA->user)->move($order, 'returned')->assertOk();

        $this->assertSame([['return_fee', -750]], $this->ledgerTypes($order));
        $this->assertSame(700, (int) $this->courierA->earnings()->sum('amount')); // ramassage 300 + retour 400

        $this->as($this->merchantUser)->getJson('/api/v1/reports/summary')
            ->assertJsonPath('data.amounts.fees', 750);
    }

    public function test_adjustments_and_ledger_immutability(): void
    {
        $this->as($this->cashier)
            ->postJson("/api/v1/finance/merchants/{$this->merchant->id}/adjustments", ['amount' => -2000, 'description' => 'Colis abîmé remboursé'])
            ->assertCreated()
            ->assertJsonPath('data.type_label', 'Ajustement');

        $entry = MerchantLedgerEntry::first();

        $this->expectException(LogicException::class);
        $entry->update(['amount' => 0]);
    }

    public function test_merchant_sees_only_its_own_finances(): void
    {
        $other = Merchant::factory()->create(['company_id' => $this->company->id]);
        $this->as($this->merchantUser);

        $this->getJson("/api/v1/finance/merchants/{$this->merchant->id}/ledger")->assertOk();
        $this->getJson("/api/v1/finance/merchants/{$other->id}/ledger")->assertForbidden();
        $this->getJson('/api/v1/finance/payouts')->assertOk();
        $this->getJson('/api/v1/finance/cash')->assertForbidden();
        $this->postJson('/api/v1/finance/payouts', ['merchant_id' => $this->merchant->id])->assertForbidden();
    }

    public function test_dispatchers_and_couriers_cannot_use_the_cash_desk(): void
    {
        $this->as($this->dispatcher)->getJson('/api/v1/finance/cash')->assertForbidden();
        $this->as($this->courierA->user)->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierA->id, 'amount_received' => 0])
            ->assertForbidden();
    }

    public function test_courier_wallet_and_payroll(): void
    {
        $this->deliver($this->createOrder(['items_amount' => 10000]));

        $this->as($this->courierB->user)->getJson('/api/v1/courier/wallet')
            ->assertOk()
            ->assertJsonPath('data.cash_in_hand', 10000)
            ->assertJsonPath('data.unpaid', 500)
            ->assertJsonPath('data.earned_today', 500)
            ->assertJsonCount(1, 'data.collections');

        $this->as($this->cashier)
            ->postJson("/api/v1/finance/couriers/{$this->courierB->id}/adjustments", ['amount' => 1000, 'description' => 'Prime'])
            ->assertCreated();

        $payout = $this->postJson('/api/v1/finance/courier-payouts', ['courier_id' => $this->courierB->id])
            ->assertCreated()
            ->assertJsonPath('data.amount', 1500)
            ->assertJsonCount(2, 'data.earnings')
            ->json('data');

        $this->postJson("/api/v1/finance/courier-payouts/{$payout['id']}/pay", ['method' => 'orange_money'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->as($this->courierB->user)->getJson('/api/v1/courier/wallet')
            ->assertJsonPath('data.unpaid', 0)
            ->assertJsonPath('data.recent_payouts.0.amount', 1500);

        $this->as($this->cashier)->postJson('/api/v1/finance/courier-payouts', ['courier_id' => $this->courierB->id])
            ->assertJsonPath('message', 'Aucun gain à payer pour ce livreur.');
    }

    public function test_payouts_of_another_company_are_not_found(): void
    {
        $this->deliver($this->createOrder());
        $this->as($this->cashier)->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierB->id, 'amount_received' => 10000]);
        $id = $this->postJson('/api/v1/finance/payouts', ['merchant_id' => $this->merchant->id])->json('data.id');

        $outsider = User::factory()->withRole(Role::Cashier)->create();
        $this->as($outsider)->getJson("/api/v1/finance/payouts/{$id}")->assertNotFound();
    }
}
