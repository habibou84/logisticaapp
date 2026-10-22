<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PlatformInvoice;
use App\Models\User;
use App\Notifications\PlatformBillingNotice;
use App\Services\Platform\Billing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Abonnements, étape 6B : factures mensuelles, paiements, impayés progressifs, rappels.
 */
class BillingTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $root;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Africa/Abidjan'));
        $this->buildWorld();
        $this->root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null]);
        $this->plan = Plan::create([
            'name' => 'Essentiel', 'price_monthly' => 25000, 'included_orders' => 2,
            'overage_per_order' => 30, 'max_couriers' => null, 'features' => [],
        ]);
        $this->company->forceFill(['plan_id' => $this->plan->id, 'created_at' => '2026-01-01 00:00:00'])->save();
    }

    private function orderOn(string $date): Order
    {
        $order = $this->createOrder();
        $order->forceFill(['created_at' => CarbonImmutable::parse("{$date} 12:00", 'Africa/Abidjan')->utc()])->save();

        return $order;
    }

    private function invoiceOctober(): PlatformInvoice
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 02:00', 'Africa/Abidjan'));
        $this->artisan('billing:invoice')->expectsOutputToContain('1 facture(s) créée(s) pour 2026-10')->assertSuccessful();

        return PlatformInvoice::query()->sole();
    }

    public function test_monthly_invoice_with_overage(): void
    {
        Notification::fake();
        foreach (['2026-10-02', '2026-10-10', '2026-10-31'] as $day) {
            $this->orderOn($day);
        }
        $this->orderOn('2026-09-30');

        $invoice = $this->invoiceOctober();

        $this->assertSame(['FAC-202610-0001', 25000, 2, 3, 1, 30, 25030, '2026-11-11'], [
            $invoice->number, $invoice->base_amount, $invoice->included_orders, $invoice->orders_count,
            $invoice->overage_orders, $invoice->overage_amount, $invoice->total, $invoice->due_at->toDateString(),
        ]);
        Notification::assertSentTo($this->admin, PlatformBillingNotice::class, fn ($n) => $n->stage === 'issued');
        Notification::assertNotSentTo($this->dispatcher, PlatformBillingNotice::class);

        // Une seule facture par mois
        $this->artisan('billing:invoice --month=2026-10')->expectsOutputToContain('0 facture(s)')->assertSuccessful();
    }

    public function test_trial_days_are_not_billed(): void
    {
        // Essai jusqu'au 15 : 16 jours facturés sur 31
        $this->company->update(['trial_ends_at' => '2026-10-15']);
        $this->orderOn('2026-10-10');
        $this->orderOn('2026-10-20');
        $this->orderOn('2026-10-21');

        $invoice = $this->invoiceOctober();
        $this->assertSame(['2026-10-16', 12903, 1, 2, 1, 12933], [
            $invoice->period_start->toDateString(), $invoice->base_amount, $invoice->included_orders,
            $invoice->orders_count, $invoice->overage_orders, $invoice->total,
        ]);

        // Mois entier en essai, ou sans formule : rien à facturer
        $billing = app(Billing::class);
        $this->company->update(['trial_ends_at' => '2026-11-30']);
        $this->assertNull($billing->invoiceMonth($this->company->fresh(), '2026-11'));
        $other = Company::factory()->create();
        $this->assertNull($billing->invoiceMonth($other, '2026-10'));
    }

    public function test_payments_and_void(): void
    {
        $invoice = $this->invoiceOctober();
        Sanctum::actingAs($this->root);
        $url = "/api/v1/console/invoices/{$invoice->id}";

        $this->postJson("{$url}/payments", ['amount' => 10000, 'method' => 'mobile_money', 'reference' => 'OM-123', 'paid_on' => '2026-11-01'])
            ->assertCreated()->assertJsonPath('data.balance', 15000)->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.payments.0.method_label', 'Mobile Money');
        $this->postJson("{$url}/payments", ['amount' => 20000, 'method' => 'cash', 'paid_on' => '2026-11-01'])->assertJsonValidationErrors('amount');
        $this->postJson("{$url}/void")->assertJsonValidationErrors('invoice');
        $this->postJson("{$url}/payments", ['amount' => 15000, 'method' => 'cash', 'paid_on' => '2026-11-01'])
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.balance', 0);
        $this->postJson("{$url}/payments", ['amount' => 1, 'method' => 'cash', 'paid_on' => '2026-11-01'])->assertJsonValidationErrors('amount');

        // Facture d'un autre mois annulée
        $this->company->update(['trial_ends_at' => null]);
        $this->postJson('/api/v1/console/invoices/generate', ['period' => '2026-09'])->assertCreated()->assertJsonPath('data.created', 1);
        $september = PlatformInvoice::query()->where('period', '2026-09')->sole();
        $this->postJson("/api/v1/console/invoices/{$september->id}/void", ['reason' => 'Mois offert'])->assertJsonPath('data.status', 'void');

        // Réservé au super administrateur
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/console/invoices')->assertForbidden();
    }

    public function test_unpaid_invoice_restricts_new_orders_progressively(): void
    {
        $invoice = $this->invoiceOctober();
        Sanctum::actingAs($this->admin);
        $state = fn () => $this->getJson('/api/v1/auth/me')->json('data.company.billing_state');

        $this->assertSame('ok', $state());

        // Échéance (11/11) passée
        $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00', 'Africa/Abidjan'));
        $this->assertSame('due', $state());

        // J+7 : bandeau
        $this->travelTo(CarbonImmutable::parse('2026-11-18 09:00', 'Africa/Abidjan'));
        $this->assertSame('warning', $state());
        $this->createOrder();

        // J+15 : plus de nouvelles courses, tout le reste consultable
        $this->travelTo(CarbonImmutable::parse('2026-11-26 09:00', 'Africa/Abidjan'));
        $this->assertSame('restricted', $state());
        $this->getJson('/api/v1/orders')->assertOk();
        try {
            $this->createOrder([], $this->admin);
            $this->fail('Création de course acceptée malgré la facture impayée.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Mon abonnement', $e->getMessage());
        }
        try {
            $this->createOrder();
            $this->fail('Création de course acceptée malgré la facture impayée.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Contactez votre entreprise de livraison', $e->getMessage());
        }

        // Payée : tout redevient normal
        app(Billing::class)->recordPayment($invoice, ['amount' => $invoice->total, 'method' => 'bank_transfer', 'paid_on' => '2026-11-26'], $this->root);
        $this->assertSame('ok', $state());
        $this->createOrder();
    }

    public function test_reminders_are_sent_once_per_stage(): void
    {
        $this->invoiceOctober();
        Notification::fake();

        $days = ['2026-11-10' => 0, '2026-11-11' => 1, '2026-11-12' => 0, '2026-11-18' => 1, '2026-11-19' => 0, '2026-11-26' => 1, '2026-11-27' => 0];
        foreach ($days as $day => $expected) {
            $this->travelTo(CarbonImmutable::parse("{$day} 08:05", 'Africa/Abidjan'));
            $this->assertSame($expected, app(Billing::class)->remind(), "Rappels du {$day}");
        }

        foreach (['due', 'warning', 'restricted'] as $stage) {
            Notification::assertSentTo($this->admin, PlatformBillingNotice::class, fn ($n) => $n->stage === $stage);
        }
    }

    public function test_company_sees_its_invoices_and_how_to_pay(): void
    {
        $invoice = $this->invoiceOctober();
        Sanctum::actingAs($this->root);
        $this->putJson('/api/v1/console/settings', ['payment_instructions' => 'Orange Money : 07 00 00 00 00'])->assertOk();
        $this->getJson('/api/v1/console/invoices')->assertOk()
            ->assertJsonPath('data.0.number', 'FAC-202610-0001')
            ->assertJsonPath('meta.totals.invoiced', 0)
            ->assertJsonPath('meta.totals.outstanding', 25000)
            ->assertJsonPath('meta.totals.mrr', 25000);
        $this->getJson("/api/v1/console/companies/{$this->company->id}")->assertJsonPath('data.invoices.0.total', 25000)
            ->assertJsonPath('data.billing.amount_due', 25000);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/subscription')->assertOk()
            ->assertJsonPath('data.invoices.0.number', 'FAC-202610-0001')
            ->assertJsonPath('data.billing.amount_due', 25000)
            ->assertJsonPath('data.payment_instructions', 'Orange Money : 07 00 00 00 00');
        $this->getJson("/api/v1/subscription/invoices/{$invoice->id}")->assertOk()->assertJsonPath('data.total', 25000);

        // Facture d'une autre entreprise : introuvable
        $other = PlatformInvoice::query()->create([...$invoice->only(['period', 'period_start', 'period_end', 'plan_name', 'base_amount', 'orders_count', 'overage_orders', 'overage_per_order', 'overage_amount', 'total', 'issued_at', 'due_at']),
            'company_id' => Company::factory()->create()->id, 'number' => 'FAC-X', 'included_orders' => 2]);
        $this->getJson("/api/v1/subscription/invoices/{$other->id}")->assertNotFound();
    }
}
