<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\CashLog;
use App\Models\CourierAdvance;
use App\Models\CourierRemittance;
use App\Models\Merchant;
use App\Models\MerchantPaymentMethod;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\CashClosed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Clôture de la caisse du jour (entrées, sorties, comptage, écart, fond, résumé, rappel) et paiements groupés
 * (e-commerçants préparés et payés ensemble, fiches de paie payées ensemble).
 */
class CashClosingTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->buildWorld();
        $this->cashier = $this->userWithRole(Role::Cashier);
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

    public function test_the_day_is_counted_closed_once_and_summarised(): void
    {
        Notification::fake();
        $this->deliver(10000);
        $this->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierB->id, 'amount_received' => 10000])->assertCreated();
        $this->postJson("/api/v1/finance/couriers/{$this->courierA->id}/advances", ['amount' => 2000, 'reason' => 'Carburant'])->assertCreated();

        // Entré 10 000 en espèces, sorti 2 000 (avance) ; premier jour : pas de début connu
        $figures = $this->getJson('/api/v1/finance/closing')->assertOk()->assertJsonPath('data.closed', null)->json('data.figures');
        $this->assertSame([10000, 2000], [$figures['total_in'], $figures['total_out']]);
        $this->assertSame(['cash', 'wave', 'orange_money'], array_column($figures['lines'], 'method'));
        $this->assertNull($figures['lines'][0]['expected']);

        // Le dispatch ne clôture pas ; les espèces sont obligatoires
        Sanctum::actingAs($this->dispatcher);
        $this->postJson('/api/v1/finance/closing', ['counted' => ['cash' => 8000], 'float_kept' => 5000])->assertForbidden();
        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/v1/finance/closing', ['counted' => ['wave' => 0], 'float_kept' => 0])->assertJsonValidationErrors('counted.cash');

        $this->postJson('/api/v1/finance/closing', ['counted' => ['cash' => 8000, 'wave' => 15000], 'float_kept' => 5000])->assertCreated()
            ->assertJsonPath('data.deposited', 3000)->assertJsonPath('data.difference', 0)->assertJsonPath('data.closed_by', $this->cashier->name);
        $this->postJson('/api/v1/finance/closing', ['counted' => ['cash' => 8000], 'float_kept' => 0])->assertUnprocessable();
        $this->getJson('/api/v1/finance/closing')->assertJsonPath('data.closed.float_kept', 5000)->assertJsonPath('data.figures', null);

        // La clôture a compté le versement et l'avance : ils ne s'annulent plus
        $remittance = CourierRemittance::latest('id')->first();
        $advance = CourierAdvance::latest('id')->first();
        $this->postJson("/api/v1/finance/remittances/{$remittance->id}/cancel", ['reason' => 'Erreur'])->assertUnprocessable();
        $this->postJson("/api/v1/finance/advances/{$advance->id}/cancel", ['reason' => 'Erreur'])->assertUnprocessable();
        $this->assertNull($remittance->fresh()->cancelled_at);
        $this->assertNull($advance->fresh()->cancelled_at);

        // Résumé : cloche et WhatsApp aux administrateurs ; noté au journal
        Notification::assertSentTo($this->admin, CashClosed::class);
        Notification::assertNotSentTo($this->cashier, CashClosed::class);
        $this->assertSame(1, OutboundMessage::where('template_name', 'cloture_caisse')->count());
        $this->assertSame('cash_closed', CashLog::latest('id')->value('action'));
    }

    public function test_the_next_day_starts_from_the_float_and_a_gap_needs_an_explanation(): void
    {
        Sanctum::actingAs($this->cashier);
        $this->travelTo(now()->subDay()->setTime(19, 0));
        $this->postJson('/api/v1/finance/closing', ['counted' => ['cash' => 50000, 'wave' => 20000], 'float_kept' => 50000])->assertCreated();
        $this->travelBack();

        $this->deliver(10000);
        $this->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierB->id, 'amount_received' => 10000])->assertCreated();

        $lines = collect($this->getJson('/api/v1/finance/closing')->json('data.figures.lines'))->keyBy('method');
        $this->assertSame([50000, 10000, 60000], [$lines['cash']['start'], $lines['cash']['in'], $lines['cash']['expected']]);
        $this->assertSame(20000, $lines['wave']['expected']);
        $this->assertNull($lines['orange_money']['start']);

        // Il manque 2 000 F : explication obligatoire
        $this->postJson('/api/v1/finance/closing', ['counted' => ['cash' => 58000, 'wave' => 20000], 'float_kept' => 50000])->assertJsonValidationErrors('explanation');
        $this->postJson('/api/v1/finance/closing', ['counted' => ['cash' => 58000, 'wave' => 20000], 'float_kept' => 60000, 'explanation' => 'Monnaie rendue'])->assertJsonValidationErrors('float_kept');
        $this->postJson('/api/v1/finance/closing', ['counted' => ['cash' => 58000, 'wave' => 20000], 'float_kept' => 50000, 'explanation' => 'Monnaie rendue'])->assertCreated()
            ->assertJsonPath('data.difference', -2000)->assertJsonPath('data.explanation', 'Monnaie rendue');

        $this->getJson('/api/v1/finance/closings')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.difference', -2000);
    }

    public function test_the_evening_reminder_goes_once_when_the_day_is_not_closed(): void
    {
        Notification::fake();
        $this->company->update(['cash_close_reminder_at' => '20:00']);
        $this->deliver(10000);
        $this->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierB->id, 'amount_received' => 10000])->assertCreated();

        $this->travelTo(today()->setTime(19, 0));
        $this->artisan('cash:close-reminder')->assertSuccessful();
        Notification::assertNotSentTo($this->admin, CashClosed::class);

        $this->travelTo(today()->setTime(20, 15));
        $this->artisan('cash:close-reminder')->assertSuccessful();
        Notification::assertSentTo([$this->admin, $this->cashier], CashClosed::class);
        Notification::assertNotSentTo($this->dispatcher, CashClosed::class);

        Notification::fake();
        $this->artisan('cash:close-reminder')->assertSuccessful();
        Notification::assertNotSentTo($this->admin, CashClosed::class);
    }

    public function test_merchant_payments_are_prepared_and_paid_together(): void
    {
        $other = Merchant::factory()->create(['company_id' => $this->company->id, 'business_name' => 'Boutique Deux', 'pickup_zone_id' => $this->cocody->id, 'pickup_address' => 'Riviera 3']);
        $this->deliver(10000);
        $order = $this->createOrder(['items_amount' => 5000], merchant: $other);
        $this->deliverExisting($order);
        $this->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierB->id, 'amount_received' => 15000])->assertCreated();

        // Moyen pas vérifié : laissé de côté
        MerchantPaymentMethod::create(['company_id' => $this->company->id, 'merchant_id' => $other->id, 'type' => 'wave', 'number' => '+2250707070707', 'is_preferred' => true, 'status' => 'pending']);

        $created = $this->postJson('/api/v1/finance/payouts/batch', ['merchant_ids' => [$this->merchant->id, $other->id]])->assertCreated()
            ->assertJsonCount(1, 'data')->assertJsonPath('errors.Boutique Deux', 'Moyen de paiement pas encore vérifié.')->json('data');

        $draft = $this->getJson('/api/v1/finance/payouts?status=draft')->assertJsonPath('data.0.pay_with.method', 'cash')->json('data.0');
        $this->postJson('/api/v1/finance/payouts/pay-batch', ['payouts' => [['id' => $created[0]['id'], 'method' => 'wave', 'transaction_ref' => 'T123']]])
            ->assertOk()->assertJsonPath('data.0.status', 'paid')->assertJsonPath('data.0.transaction_ref', 'T123');
        $this->assertSame($created[0]['id'], $draft['id']);
        $this->postJson('/api/v1/finance/payouts/pay-batch', ['payouts' => [['id' => $created[0]['id'], 'method' => 'wave']]])
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonCount(1, 'errors');
    }

    public function test_payslips_are_paid_together(): void
    {
        $this->deliver(10000);
        $this->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierB->id, 'amount_received' => 10000])->assertCreated();
        $id = $this->postJson('/api/v1/finance/courier-payouts', ['courier_id' => $this->courierB->id])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->dispatcher);
        $this->postJson('/api/v1/finance/courier-payouts/pay-batch', ['ids' => [$id], 'method' => 'cash'])->assertForbidden();
        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/v1/finance/courier-payouts/pay-batch', ['ids' => [$id], 'method' => 'cash'])->assertOk()
            ->assertJsonPath('data.0.status', 'paid');
        $this->assertSame(1, CashLog::where('action', 'payslip_paid')->count());

        // Comptée en sortie d'espèces dans la clôture
        $this->assertSame(500, $this->getJson('/api/v1/finance/closing')->json('data.figures.lines.0.out'));
    }

    private function deliverExisting(Order $order): void
    {
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();
        Sanctum::actingAs($this->cashier);
    }
}
