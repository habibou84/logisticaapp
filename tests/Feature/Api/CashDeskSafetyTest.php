<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\CashCollection;
use App\Models\CashLog;
use App\Models\CourierEarning;
use App\Models\CourierRemittance;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\CashHeldAlert;
use App\Services\Finance\CashAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Caisse : reçu du versement (WhatsApp au livreur), annulation le jour même d'un versement ou d'une avance, journal
 * de la caisse, livreurs à surveiller et alerte quand l'argent est gardé trop longtemps, solde du grand livre.
 */
class CashDeskSafetyTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->buildWorld();
        $this->cashier = $this->userWithRole(Role::Cashier);
        $this->courierB->user->update(['phone' => '+2250707000002']);
        $this->payPlan(['pickup' => 300, 'delivery' => 500], $this->courierB);
    }

    private function deliver(int $amount): Order
    {
        $order = $this->createOrder(['items_amount' => $amount]);
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();
        Sanctum::actingAs($this->cashier);

        return $order->fresh();
    }

    public function test_remittance_sends_a_receipt_and_can_be_cancelled_the_same_day(): void
    {
        $this->deliver(10000);
        $this->deliver(5000);

        $id = $this->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierB->id, 'amount_received' => 14000])
            ->assertCreated()->assertJsonPath('data.difference', -1000)->json('data.id');
        $remittance = CourierRemittance::findOrFail($id);

        // Reçu WhatsApp au livreur
        $receipt = OutboundMessage::where('template_name', 'versement_recu')->sole();
        $this->assertSame('+2250707000002', $receipt->to);
        $this->assertStringContainsString('14 000 F (attendu 15 000 F)', $receipt->body);
        $this->assertStringContainsString('Écart de 1 000 F retenu sur votre paie.', $receipt->body);

        // Reçu détaillé
        $this->getJson("/api/v1/finance/remittances/{$id}")->assertOk()
            ->assertJsonPath('data.reference', $remittance->reference())
            ->assertJsonCount(2, 'data.collections')->assertJsonPath('data.can_cancel', true);

        // Annulation : raison obligatoire, l'argent revient chez le livreur, le manque retenu disparaît
        $this->postJson("/api/v1/finance/remittances/{$id}/cancel")->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/finance/remittances/{$id}/cancel", ['reason' => 'montant mal saisi'])->assertOk()
            ->assertJsonPath('data.cancel_reason', 'montant mal saisi')->assertJsonPath('data.cancelled_by', $this->cashier->name);
        $this->assertSame(2, CashCollection::inCourierHands()->count());
        $this->assertSame(0, CourierEarning::where('type', 'shortfall')->count());
        $this->getJson('/api/v1/finance/cash')->assertJsonPath('totals.cash_in_hands', 15000)->assertJsonPath('totals.received_today', 0);
        $this->postJson("/api/v1/finance/remittances/{$id}/cancel", ['reason' => 'encore'])->assertUnprocessable();

        // Journal : versement puis annulation
        $this->assertSame(['remittance', 'remittance_cancelled'], CashLog::orderBy('id')->pluck('action')->all());
        $this->getJson('/api/v1/finance/journal?action=remittance_cancelled')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.by', $this->cashier->name);
    }

    public function test_a_remittance_from_another_day_or_already_paid_out_cannot_be_cancelled(): void
    {
        $this->deliver(10000);
        $id = $this->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierB->id, 'amount_received' => 10000])->json('data.id');

        // Paiement préparé pour l'e-commerçant avec cet argent
        $this->postJson('/api/v1/finance/payouts', ['merchant_id' => $this->merchant->id])->assertCreated();
        $this->postJson("/api/v1/finance/remittances/{$id}/cancel", ['reason' => 'erreur'])->assertUnprocessable()
            ->assertJsonPath('message', 'Un e-commerçant a déjà un paiement préparé ou fait avec cet argent : annulez d\'abord ce paiement.');

        CourierRemittance::whereKey($id)->update(['received_at' => now()->subDay()]);
        $this->postJson("/api/v1/finance/remittances/{$id}/cancel", ['reason' => 'erreur'])->assertUnprocessable();
        $this->getJson("/api/v1/finance/remittances/{$id}")->assertJsonPath('data.can_cancel', false);
    }

    public function test_an_advance_can_be_cancelled_the_same_day(): void
    {
        Sanctum::actingAs($this->cashier);
        $advance = $this->postJson("/api/v1/finance/couriers/{$this->courierB->id}/advances", ['amount' => 5000, 'reason' => 'Frais de gare'])
            ->assertCreated()->assertJsonPath('data.can_cancel', true)->json('data.id');
        $this->getJson('/api/v1/finance/cash')->assertJsonPath('totals.cash_in_hands', 5000);

        $this->postJson("/api/v1/finance/advances/{$advance}/cancel", ['reason' => 'donnée par erreur'])->assertOk();
        $this->getJson('/api/v1/finance/cash')->assertJsonPath('totals.cash_in_hands', 0);
        $this->postJson("/api/v1/finance/advances/{$advance}/cancel", ['reason' => 'encore'])->assertUnprocessable();
        $this->assertSame(['advance', 'advance_cancelled'], CashLog::orderBy('id')->pluck('action')->all());

        // Livreur et dispatch : ni journal ni annulation
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/finance/journal')->assertForbidden();
    }

    public function test_couriers_keeping_cash_too_long_are_watched_and_alerted_once_a_day(): void
    {
        Notification::fake();
        $this->deliver(10000);
        $this->company->update(['cash_alert_hours' => 24, 'cash_alert_amount' => null]);

        $this->getJson('/api/v1/finance/cash')->assertJsonPath('data.0.watch', null)->assertJsonPath('totals.watched', 0);
        $this->assertSame(0, app(CashAlerts::class)->run());

        CashCollection::query()->update(['collected_at' => now()->subHours(30)]);
        $this->getJson('/api/v1/finance/cash')->assertJsonPath('data.0.watch', 'Garde l\'argent depuis 1 jour')->assertJsonPath('totals.watched', 1);

        $this->assertSame(1, app(CashAlerts::class)->run());
        Notification::assertSentTo([$this->admin, $this->cashier], CashHeldAlert::class);
        Notification::assertNotSentTo($this->dispatcher, CashHeldAlert::class);
        $this->assertSame(1, OutboundMessage::where('template_name', 'argent_a_remettre')->count());
        // Une fois par jour
        $this->assertSame(0, app(CashAlerts::class)->run());

        // Plafond dépassé, même récent
        $this->company->update(['cash_alert_hours' => 0, 'cash_alert_amount' => 5000]);
        Sanctum::actingAs($this->cashier->fresh());
        $this->getJson('/api/v1/finance/cash')->assertJsonPath('data.0.watch', 'Au-dessus du plafond de 5 000 F');
    }

    public function test_ledger_shows_the_balance_after_each_line(): void
    {
        $this->deliver(10000);
        $this->postJson("/api/v1/finance/merchants/{$this->merchant->id}/adjustments", ['amount' => 2000, 'description' => 'Geste commercial'])->assertCreated();

        $rows = $this->getJson("/api/v1/finance/merchants/{$this->merchant->id}/ledger")->assertOk()->json('data');
        $total = array_sum(array_column($rows, 'amount'));
        $this->assertSame($total, $rows[0]['balance']);
        $this->assertSame($total - 2000, $rows[1]['balance']);
        $this->assertSame('merchant_adjustment', CashLog::sole()->action);
    }
}
