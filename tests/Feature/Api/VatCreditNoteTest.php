<?php

namespace Tests\Feature\Api;

use App\Models\BillingPlan;
use App\Models\ClientInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * TVA des factures des clients (réglage, exonération, identité fiscale) et avoirs.
 */
class VatCreditNoteTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo('2026-11-10 10:00:00');
        foreach ([$this->cocody, $this->yopougon] as $zone) {
            $zone->update(['in_forfait' => true]);
        }
        Sanctum::actingAs($this->admin);
        $id = $this->postJson("/api/v1/merchants/{$this->merchant->id}/billing-plans", [
            'formula' => 'distribution_contract', 'monthly_fee' => 50000, 'included_quantity' => 2, 'overage_price' => 1000,
            'delivery_delay' => '48h', 'cheque_price' => 1500, 'extra_kg_price' => 300, 'starts_on' => '2026-11-01',
        ])->assertCreated()->json('data.id');
        $this->merchant->syncActivities([BillingPlan::findOrFail($id)->activity]);
        // 5 plis : 2 dans le forfait, 3 au-delà (3 × 1 000 F)
        foreach (range(1, 5) as $i) {
            $this->createOrder(['shipment_type' => 'pli']);
        }
    }

    private function vat(array $settings): void
    {
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['vat_settings' => $settings])->assertOk();
    }

    private function invoice(): ClientInvoice
    {
        $this->travelTo('2026-12-01 08:00:00');
        $this->artisan('billing:invoice', ['--month' => '2026-11'])->assertSuccessful();

        return ClientInvoice::sole();
    }

    private function issued(): ClientInvoice
    {
        $invoice = $this->invoice();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/issue")->assertOk();

        return $invoice->fresh();
    }

    public function test_without_vat_nothing_changes(): void
    {
        $invoice = $this->invoice();
        $this->assertSame([53000, 53000, 0], [$invoice->total, $invoice->total_ht, $invoice->vat_amount]);
    }

    public function test_vat_is_added_to_prices_without_tax_or_included_in_prices_with_tax(): void
    {
        $this->vat(['enabled' => true, 'rate' => 18, 'prices' => 'ht']);
        $invoice = $this->invoice();
        $this->assertSame([53000, 9540, 62540], [$invoice->total_ht, $invoice->vat_amount, $invoice->total]);

        // Paiement limité au TTC
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/issue")->assertOk()
            ->assertJsonPath('data.total_ht', 53000)->assertJsonPath('data.vat_amount', 9540)->assertJsonPath('data.balance', 62540);

        // Prix TTC : la TVA est dans le prix
        $this->vat(['enabled' => true, 'rate' => 18, 'prices' => 'ttc']);
        $this->assertSame([62540, 9540], [$invoice->fresh()->total, $invoice->fresh()->vat_amount]);
        ClientInvoice::query()->delete();
        $this->artisan('billing:invoice', ['--month' => '2026-11'])->assertSuccessful();
        $ttc = ClientInvoice::sole();
        $this->assertSame([44915, 8085, 53000], [$ttc->total_ht, $ttc->vat_amount, $ttc->total]);
    }

    public function test_an_exempt_client_and_the_fiscal_identity(): void
    {
        $this->vat(['enabled' => true, 'rate' => 18, 'prices' => 'ht']);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['legal_name' => 'Logistica SARL', 'ncc' => '1234567A'])->assertOk();
        $this->patchJson("/api/v1/merchants/{$this->merchant->id}", [
            'legal_name' => 'Imperial Tobacco CI SA', 'ncc' => '7654321B', 'vat_exempt' => true, 'vat_exemption_ref' => 'EXO-2026-42', 'vat_exemption_until' => '2026-12-31',
        ])->assertOk()->assertJsonPath('data.ncc', '7654321B');

        $invoice = $this->issued();
        $this->assertSame([53000, 0, 53000], [$invoice->total_ht, $invoice->vat_amount, $invoice->total]);
        $this->getJson("/api/v1/client-invoices/{$invoice->id}")->assertOk()
            ->assertJsonPath('data.vat_exemption', 'Exonéré de TVA · attestation n° EXO-2026-42')
            ->assertJsonPath('data.client.ncc', '7654321B')
            ->assertJsonPath('data.issuer.ncc', '1234567A');

        // Identité figée à l'émission
        $this->patchJson("/api/v1/merchants/{$this->merchant->id}", ['ncc' => '0000000Z'])->assertOk();
        $this->getJson("/api/v1/client-invoices/{$invoice->id}")->assertJsonPath('data.client.ncc', '7654321B');

        // Attestation échue : TVA de nouveau
        $this->merchant->update(['vat_exemption_until' => '2026-11-30']);
        ClientInvoice::query()->delete();
        $this->artisan('billing:invoice', ['--month' => '2026-11'])->assertSuccessful();
        $this->assertSame(9540, ClientInvoice::sole()->vat_amount);
    }

    public function test_vat_settings_belong_to_billing(): void
    {
        Sanctum::actingAs($this->dispatcher);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['vat_settings' => ['enabled' => true]])->assertForbidden();
    }

    public function test_partial_credit_note(): void
    {
        $this->vat(['enabled' => true, 'rate' => 18, 'prices' => 'ht']);
        $invoice = $this->issued();
        $overage = $invoice->lines()->where('kind', 'overage')->sole();

        // Sans le droit : refusé ; plus que facturé : refusé
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/credit-notes", ['reason' => 'x', 'full' => false])->assertForbidden();
        Sanctum::actingAs($this->admin);
        $credit = fn (array $data) => $this->postJson("/api/v1/client-invoices/{$invoice->id}/credit-notes", ['reason' => 'Plis déjà réceptionnés', 'full' => false, ...$data]);
        $credit(['lines' => [['line_id' => $overage->id, 'quantity' => 4]]])->assertUnprocessable();

        // 2 plis repris + 500 F de geste : 2 500 HT, 450 TVA, 2 950 TTC
        $credit(['lines' => [['line_id' => $overage->id, 'quantity' => 2]], 'extra' => ['label' => 'Geste commercial', 'amount' => 500]])
            ->assertCreated()
            ->assertJsonPath('data.credit_notes.0.number', 'AV-202612-0001')
            ->assertJsonPath('data.credit_notes.0.total', 2950)
            ->assertJsonPath('data.credited_amount', 2950)
            ->assertJsonPath('data.balance', 62540 - 2950)
            ->assertJsonPath('data.lines.2.credited_quantity', 2);
        // Il reste un pli à reprendre
        $credit(['lines' => [['line_id' => $overage->id, 'quantity' => 2]]])->assertUnprocessable();

        // Chiffre d'affaires : hors taxe ; l'avoir en moins le mois où il est fait
        $revenue = $this->getJson('/api/v1/revenue?from=2026-11&to=2026-12')->assertOk()->json('data.months');
        $this->assertSame(53000, $revenue[0]['activities']['distribution']['billed']);
        $this->assertSame(-2500, $revenue[1]['activities']['distribution']['billed']);

        // Le client voit l'avoir ; l'annulation simple n'est plus possible
        Sanctum::actingAs($this->merchantUser);
        $this->getJson("/api/v1/client-invoices/{$invoice->id}")->assertOk()->assertJsonCount(1, 'data.credit_notes');
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/void")->assertUnprocessable();
    }

    public function test_full_credit_note_cancels_the_invoice_or_leaves_a_refund(): void
    {
        $invoice = $this->issued();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/credit-notes", ['reason' => 'Contrat résilié', 'full' => true])->assertCreated()
            ->assertJsonPath('data.status', 'void')->assertJsonPath('data.status_label', 'Annulée par avoir')
            ->assertJsonPath('data.credit_notes.0.total', 53000)->assertJsonPath('data.balance', 0);
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/credit-notes", ['reason' => 'x', 'full' => true])->assertUnprocessable();
        // Le client la garde dans ses factures
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/client-invoices')->assertOk()->assertJsonPath('data.0.status_label', 'Annulée par avoir');
    }

    public function test_credit_on_a_paid_invoice_is_refunded(): void
    {
        $invoice = $this->issued();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/payments", ['amount' => 53000, 'method' => 'bank_transfer', 'paid_on' => '2026-12-01'])
            ->assertOk()->assertJsonPath('data.status', 'paid');
        $overage = $invoice->lines()->where('kind', 'overage')->sole();
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/credit-notes", ['reason' => 'Plis refusés', 'full' => false, 'lines' => [['line_id' => $overage->id, 'quantity' => 1]]])
            ->assertCreated()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.refund_due', 1000)
            ->assertJsonPath('data.credit_notes.0.refund_due', 1000);
    }
}
