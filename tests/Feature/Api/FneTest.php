<?php

namespace Tests\Feature\Api;

use App\Models\BillingPlan;
use App\Models\ClientInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Facture normalisée électronique (FNE, DGI) : certification à l'émission, avoirs, relances (réponses de la
 * procédure d'interfaçage de mai 2025).
 */
class FneTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const URL = 'http://54.247.95.108/ws';

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
        foreach (range(1, 5) as $i) {
            $this->createOrder(['shipment_type' => 'pli']);
        }
        $this->merchant->update(['legal_name' => 'KPMG COTE D\'IVOIRE', 'ncc' => '9502363N', 'email' => 'info@kpmg.ci', 'phone' => '0709080765']);
        $this->patchJson("/api/v1/companies/{$this->company->id}", [
            'vat_settings' => ['enabled' => true, 'rate' => 18, 'prices' => 'ht'],
            'fne_settings' => ['enabled' => true, 'mode' => 'test', 'point_of_sale' => '23', 'establishment' => 'Siège Plateau'],
            'fne_api_key' => 'kAF01gEM40r1Uz5WLJn5lxAnGMwVjCME',
        ])->assertOk()->assertJsonPath('data.fne_enabled', true)->assertJsonPath('data.fne_api_key_set', true)->assertJsonMissingPath('data.fne_api_key');

        $this->travelTo('2026-12-01 08:00:00');
        $this->artisan('billing:invoice', ['--month' => '2026-11'])->assertSuccessful();
    }

    /** Réponse de certification : un article FNE par ligne envoyée, dans l'ordre */
    private function signed(Request $request, string $reference = '9606123E25000000019'): array
    {
        return [
            'ncc' => '9606123E', 'reference' => $reference, 'token' => 'http://54.247.95.108/fr/verification/019465c1-3f61', 'warning' => false, 'balance_sticker' => 179,
            'invoice' => ['id' => 'e2b2d8da-a532', 'items' => array_map(fn ($item, $i) => ['id' => "item-{$i}", 'reference' => $item['reference'], 'quantity' => $item['quantity']], $request['items'], array_keys($request['items']))],
        ];
    }

    public function test_the_invoice_is_certified_when_issued(): void
    {
        Http::fake([self::URL.'/external/invoices/sign' => fn (Request $r) => Http::response($this->signed($r))]);
        $invoice = ClientInvoice::sole();

        $this->postJson("/api/v1/client-invoices/{$invoice->id}/issue")->assertOk()
            ->assertJsonPath('data.fne.status', 'certified')
            ->assertJsonPath('data.fne.reference', '9606123E25000000019')
            ->assertJsonPath('data.fne.verification_url', 'http://54.247.95.108/fr/verification/019465c1-3f61');

        Http::assertSent(function (Request $r) {
            $items = collect($r['items']);

            return $r->hasHeader('Authorization', 'Bearer kAF01gEM40r1Uz5WLJn5lxAnGMwVjCME')
                && $r['invoiceType'] === 'sale' && $r['template'] === 'B2B' && $r['paymentMethod'] === 'deferred'
                && $r['clientNcc'] === '9502363N' && $r['clientCompanyName'] === 'KPMG COTE D\'IVOIRE' && $r['clientPhone'] === '0709080765'
                && $r['pointOfSale'] === '23' && $r['establishment'] === 'Siège Plateau'
                // Forfait et plis au-delà (prix hors taxe) ; la ligne « inclus » à 0 F n'est pas envoyée
                && $items->count() === 2 && $items->every(fn ($i) => $i['taxes'] === ['TVA'])
                && $items->pluck('amount')->all() === [50000.0, 1000.0] && $items->pluck('quantity')->all() === [1, 3];
        });
        $this->assertSame(179, $this->company->fresh()->fne_balance_sticker);
        // Certifiée : elle ne s'annule plus
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/void")->assertUnprocessable();
    }

    public function test_a_refused_invoice_stays_a_draft(): void
    {
        Http::fake([self::URL.'/*' => Http::response(['message' => 'Point of sale is not valid', 'error' => 'bad_request', 'statusCode' => 400], 400)]);
        $invoice = ClientInvoice::sole();

        $this->postJson("/api/v1/client-invoices/{$invoice->id}/issue")->assertUnprocessable()
            ->assertJsonValidationErrors(['fne' => 'Point of sale is not valid']);
        $this->assertSame('draft', $invoice->fresh()->status->value);
        $this->assertNull($invoice->fresh()->number);

        // Données manquantes : refusé avant l'envoi
        $this->merchant->update(['email' => null]);
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/issue")->assertUnprocessable()->assertJsonValidationErrors('fne');
        Http::assertSentCount(1);
    }

    public function test_an_unreachable_fne_is_retried(): void
    {
        $down = true;
        Http::fake([self::URL.'/external/invoices/sign' => function (Request $r) use (&$down) {
            if ($down) {
                throw new ConnectionException('timeout');
            }

            return Http::response($this->signed($r));
        }]);
        $invoice = ClientInvoice::sole();
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/issue")->assertOk()
            ->assertJsonPath('data.status', 'issued')->assertJsonPath('data.fne.status', 'pending');
        // Pas d'avoir tant qu'elle n'est pas certifiée
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/credit-notes", ['reason' => 'x', 'full' => true])->assertUnprocessable();

        $down = false;
        $this->artisan('fne:retry')->assertSuccessful();
        $this->assertSame('certified', $invoice->fresh()->fne_status);
        $this->assertSame('9606123E25000000019', $invoice->fresh()->fne_reference);
    }

    public function test_credit_notes_are_certified_item_by_item(): void
    {
        Http::fake([
            self::URL.'/external/invoices/sign' => fn (Request $r) => Http::response($this->signed($r)),
            self::URL.'/external/invoices/e2b2d8da-a532/refund' => Http::response(['ncc' => '9606123E', 'reference' => 'A9606123E2500000006', 'token' => 'http://54.247.95.108/fr/verification/019465ca', 'warning' => false, 'balance_sticker' => 178], 201),
        ]);
        $invoice = ClientInvoice::sole();
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/issue")->assertOk();
        $overage = $invoice->lines()->where('kind', 'overage')->sole();

        // Montant libre : impossible sur une facture normalisée
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/credit-notes", ['reason' => 'Geste', 'full' => false, 'extra' => ['label' => 'Geste', 'amount' => 500]])
            ->assertUnprocessable();

        $this->postJson("/api/v1/client-invoices/{$invoice->id}/credit-notes", ['reason' => 'Plis déjà réceptionnés', 'full' => false, 'lines' => [['line_id' => $overage->id, 'quantity' => 2]]])
            ->assertCreated()
            ->assertJsonPath('data.credit_notes.0.fne.status', 'certified')
            ->assertJsonPath('data.credit_notes.0.fne.reference', 'A9606123E2500000006');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/refund') && $r['items'] === [['id' => 'item-1', 'quantity' => 2]]);
        $this->assertSame(178, $this->company->fresh()->fne_balance_sticker);
    }

    public function test_exempt_client_and_tax_codes(): void
    {
        Http::fake([self::URL.'/external/invoices/sign' => fn (Request $r) => Http::response($this->signed($r))]);
        $this->merchant->update(['vat_exempt' => true, 'vat_exemption_ref' => 'EXO-42', 'vat_exemption_type' => 'legal', 'fne_template' => 'B2G']);
        $invoice = ClientInvoice::sole();
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/issue")->assertOk();
        Http::assertSent(fn (Request $r) => $r['template'] === 'B2G' && $r['items'][0]['taxes'] === ['TVAD']);
    }
}
