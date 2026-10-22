<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\OrderExpense;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Zones d'expédition : le livreur dépose le colis à une gare et paie le transporteur ;
 * les frais sont facturés au marchand et remboursés au livreur sur son versement.
 */
class ShippingTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private Zone $station;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->cashier = $this->userWithRole(Role::Cashier);
        $this->payPlan(['delivery' => 500], $this->courierB);

        $this->station = Zone::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Expédition Bouaké',
            'is_shipping' => true,
            'shipping_fee_estimate' => 3000,
        ]);
        $this->rule($this->grid, $this->cocody, $this->station, 1500);
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

    private function onTheWay(array $data = []): Order
    {
        $order = $this->createOrder(['delivery_zone_id' => $this->station->id, ...$data]);
        $this->as($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        $this->as($this->courierA->user)->move($order, 'picked_up')->assertOk();
        $this->as($this->courierB->user)->move($order, 'out_for_delivery')->assertOk();

        return $order->fresh();
    }

    private function ship(Order $order, int $fee = 3000): Order
    {
        $this->as($this->courierB->user)->move($order, 'delivered', [
            'shipping_fee' => $fee,
            'shipping_carrier' => 'UTB Adjamé',
            'shipping_reference' => 'A-15234',
        ])->assertOk()->assertJsonPath('data.status_label', 'Expédié');

        return $order->fresh();
    }

    public function test_shipping_zone_is_managed_and_quoted(): void
    {
        $this->as($this->admin)->postJson('/api/v1/zones', [
            'name' => 'Expédition Korhogo', 'is_shipping' => true, 'shipping_fee_estimate' => 5000,
        ])->assertCreated()
            ->assertJsonPath('data.is_shipping', true)
            ->assertJsonPath('data.shipping_fee_estimate', 5000);

        $this->as($this->merchantUser)->postJson('/api/v1/quotes', ['delivery_zone_id' => $this->station->id])
            ->assertOk()
            ->assertJsonPath('data.total', 1500)
            ->assertJsonPath('data.is_shipping', true)
            ->assertJsonPath('data.shipping_fee_estimate', 3000);
    }

    public function test_shipping_requires_carrier_and_fee_but_not_the_delivery_code(): void
    {
        $this->company->update(['require_delivery_code' => true]);
        $order = $this->onTheWay();
        $this->assertTrue($order->is_shipping);

        $this->move($order, 'delivered')->assertJsonValidationErrors('shipping_carrier');
        $this->move($order, 'delivered', ['shipping_carrier' => 'UTB'])->assertJsonValidationErrors('shipping_fee');

        $order = $this->ship($order);
        $this->assertSame(3000, $order->shipping_fee);
        $this->assertSame('UTB Adjamé', $order->shipping_carrier);
        $this->assertSame('A-15234', $order->shipping_reference);

        $event = $order->events()->latest('id')->first();
        $this->assertSame('UTB Adjamé', $event->meta['shipping_carrier']);

        $this->as($this->merchantUser)->getJson("/api/v1/orders/{$order->id}")
            ->assertJsonPath('data.shipping.fee', 3000)
            ->assertJsonPath('data.shipping.carrier', 'UTB Adjamé');
    }

    public function test_fees_are_charged_to_the_merchant_and_refunded_to_the_courier(): void
    {
        // Le client paie 10 000 F d'articles à la livraison… chez le livreur avant l'expédition
        $order = $this->ship($this->onTheWay(['items_amount' => 10000]));

        $ledger = MerchantLedgerEntry::where('order_id', $order->id)->orderBy('id')->get()
            ->map(fn ($e) => [$e->type->value, $e->amount])->all();
        $this->assertSame([['cod_credit', 10000], ['delivery_fee', -1500], ['shipping_fee', -3000]], $ledger);

        $expense = OrderExpense::where('order_id', $order->id)->sole();
        $this->assertSame(['shipping', 3000, 'courier', $this->courierB->id, 'merchant'],
            [$expense->type->value, $expense->amount, $expense->paid_by, $expense->courier_id, $expense->billed_to]);
        $this->assertSame('UTB Adjamé, ticket A-15234', $expense->label);

        $this->as($this->courierB->user)->getJson('/api/v1/courier/wallet')
            ->assertJsonPath('data.cash_in_hand', 7000)
            ->assertJsonPath('data.balance.collected', 10000)
            ->assertJsonPath('data.expenses.0.amount', 3000);

        $this->as($this->cashier)->postJson('/api/v1/finance/remittances', [
            'courier_id' => $this->courierB->id, 'amount_received' => 7000,
        ])->assertCreated()->assertJsonPath('data.amount_expected', 7000)->assertJsonPath('data.difference', 0);
        $this->assertNotNull($expense->fresh()->remittance_id);
    }

    public function test_prepaid_shipment_means_the_cash_desk_owes_the_courier(): void
    {
        $this->ship($this->onTheWay(['items_amount' => 0]));

        $this->as($this->cashier)->getJson('/api/v1/finance/cash')
            ->assertJsonPath('data.0.courier_id', $this->courierB->id)
            ->assertJsonPath('data.0.cash_in_hand', -3000);

        // La caisse rend 3 000 F au livreur : aucun écart
        $this->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierB->id, 'amount_received' => -3000])
            ->assertCreated()->assertJsonPath('data.difference', 0);
        $this->assertSame(500, (int) $this->courierB->earnings()->sum('amount'));

        $payout = $this->postJson('/api/v1/finance/payouts', ['merchant_id' => $this->merchant->id])->assertCreated()->json('data');
        $this->assertSame(1500, $payout['total_fees']);
        $this->assertSame(3000, $payout['total_shipping_fees']);
        $this->assertSame(-4500, $payout['net_amount']);
    }

    public function test_cash_desk_can_advance_the_station_fees(): void
    {
        // La caisse remet 5 000 F au livreur avant son départ pour la gare
        $this->as($this->cashier)->postJson("/api/v1/finance/couriers/{$this->courierB->id}/advances", [
            'amount' => 5000, 'reason' => 'Frais de gare',
        ])->assertCreated()->assertJsonPath('data.amount', 5000);

        $this->ship($this->onTheWay(['items_amount' => 0]), 3500);

        // Il rend la monnaie : 5 000 - 3 500
        $this->as($this->courierB->user)->getJson('/api/v1/courier/wallet')
            ->assertJsonPath('data.cash_in_hand', 1500)
            ->assertJsonPath('data.balance.advances', 5000)
            ->assertJsonPath('data.balance.expenses', 3500)
            ->assertJsonPath('data.advances.0.reason', 'Frais de gare');

        $this->as($this->cashier)->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierB->id, 'amount_received' => 1500])
            ->assertCreated()->assertJsonPath('data.amount_expected', 1500)->assertJsonPath('data.difference', 0);
        $this->getJson('/api/v1/finance/cash')->assertJsonPath('data.0.cash_in_hand', 0);
    }

    public function test_station_fees_paid_directly_by_the_agency(): void
    {
        $order = $this->onTheWay(['items_amount' => 0]);
        $this->as($this->dispatcher)->move($order, 'delivered', [
            'shipping_fee' => 3000, 'shipping_carrier' => 'UTB', 'shipping_paid_by' => 'company',
        ])->assertOk();

        $this->assertSame('company', OrderExpense::sole()->paid_by);
        $this->assertSame(-3000, (int) MerchantLedgerEntry::where('type', 'shipping_fee')->sum('amount'));
        $this->as($this->cashier)->getJson('/api/v1/finance/cash')->assertJsonPath('data.0.cash_in_hand', 0);
    }

    public function test_point_shows_shipping_fees_separately(): void
    {
        $this->ship($this->onTheWay(['items_amount' => 10000]));

        $this->as($this->merchantUser)->getJson('/api/v1/reports/summary')
            ->assertOk()
            ->assertJsonPath('data.counts.delivered', 1)
            ->assertJsonPath('data.counts.shipped', 1)
            ->assertJsonPath('data.amounts.collected', 10000)
            ->assertJsonPath('data.amounts.fees', 1500)
            ->assertJsonPath('data.amounts.shipping_fees', 3000)
            ->assertJsonPath('data.amounts.net_to_merchant', 5500);
    }

    public function test_shipment_messages_replace_the_door_to_door_ones(): void
    {
        $order = $this->onTheWay();
        $this->assertSame(0, OutboundMessage::count(), 'pas de « colis en route » pour une expédition');

        $this->ship($order);

        $this->assertEqualsCanonicalizing(['colis_expedie', 'colis_expedie_client'], OutboundMessage::pluck('template_name')->all());
        $toMerchant = OutboundMessage::where('template_name', 'colis_expedie')->sole();
        $this->assertSame(['Boutique Test', $order->tracking_code, 'Jean Kouadio (Expédition Bouaké)', 'UTB Adjamé', 'A-15234', '3 000 F'], $toMerchant->payload);

        $this->getJson("/api/v1/tracking/{$order->tracking_code}")
            ->assertJsonPath('data.status_label', 'Expédié')
            ->assertJsonPath('data.shipping_carrier', 'UTB Adjamé');
    }
}
