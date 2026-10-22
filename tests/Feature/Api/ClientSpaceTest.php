<?php

namespace Tests\Feature\Api;

use App\Enums\Activity;
use App\Models\BillingPlan;
use App\Models\ClientInvoice;
use App\Models\CourierAbsence;
use App\Models\DistributionRate;
use App\Models\Order;
use App\Notifications\ClientBillingAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Application des clients distribution et MAD : pas d'encaissement, forfait du mois, livreurs attitrés,
 * factures et leurs notifications, import et statistiques par activité.
 */
class ClientSpaceTest extends TestCase
{
    use BuildsDeliveryWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo('2026-11-10 10:00:00');
        foreach ([$this->cocody, $this->yopougon, $this->plateau] as $zone) {
            $zone->update(['in_forfait' => true]);
        }
    }

    private function plan(string $formula = 'distribution_contract', array $attributes = []): BillingPlan
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson("/api/v1/merchants/{$this->merchant->id}/billing-plans", [
            'formula' => $formula,
            'monthly_fee' => 50000,
            'included_quantity' => 5,
            'overage_price' => 800,
            'delivery_delay' => '48h',
            'starts_on' => '2026-11-01',
            ...$attributes,
        ])->assertCreated()->json('data.id');

        return BillingPlan::findOrFail($id);
    }

    private function send(array $data = [])
    {
        Sanctum::actingAs($this->merchantUser);

        return $this->postJson('/api/v1/orders', [
            'recipient_phone' => '0707070707',
            'delivery_zone_id' => $this->yopougon->id,
            'activity' => 'distribution',
            'shipment_type' => 'pli',
            ...$data,
        ]);
    }

    public function test_distribution_and_mad_courses_collect_nothing(): void
    {
        $this->plan();

        // Sans « qui paie » : toujours le client, facturé au mois
        $order = $this->send()->assertCreated()->json('data');
        $this->assertSame(0, $order['amounts']['cod_amount']);
        $this->assertSame('merchant', Order::find($order['id'])->fee_payer->value);

        $this->send(['items_amount' => 15000, 'fee_payer' => 'merchant'])->assertUnprocessable()->assertJsonValidationErrors('items_amount');
        $this->send(['fee_payer' => 'recipient'])->assertUnprocessable();

        // Modification : pas d'encaissement non plus
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/orders/{$order['id']}", ['items_amount' => 5000])->assertUnprocessable();

        // L'e-commerce garde son encaissement
        $this->send(['activity' => 'ecommerce', 'shipment_type' => null, 'items_amount' => 15000, 'fee_payer' => 'recipient'])->assertCreated();
    }

    public function test_the_client_sees_its_forfait_of_the_month(): void
    {
        $plan = $this->plan();
        foreach (range(1, 6) as $i) {
            $this->send()->assertCreated();
        }

        Sanctum::actingAs($this->merchantUser);
        $data = $this->getJson('/api/v1/merchant/space')->assertOk()->json('data');

        $this->assertSame(['ecommerce', 'distribution'], array_column($data['activities'], 'value'));
        $this->assertCount(1, $data['plans']);
        $usage = $data['plans'][0];
        $this->assertSame($plan->id, $usage['id']);
        $this->assertSame('Novembre 2026', $usage['month_label']);
        $this->assertSame([5, 6, 0, 1, 120], [$usage['included'], $usage['used'], $usage['remaining'], $usage['over'], $usage['percent']]);
        // Forfait + 1 pli en plus
        $this->assertSame(50800, $usage['estimate']);
        $this->assertSame(0, $data['invoices']['to_pay']);
    }

    public function test_forfait_alerts_at_80_then_100_percent_once(): void
    {
        Notification::fake();
        $this->plan();

        foreach (range(1, 3) as $i) {
            $this->send()->assertCreated();
        }
        Notification::assertNotSentTo($this->merchantUser, ClientBillingAlert::class);

        $this->send()->assertCreated(); // 4/5 : 80 %
        Notification::assertSentToTimes($this->merchantUser, ClientBillingAlert::class, 1);
        $this->send()->assertCreated(); // 5/5 : 100 %
        $this->send()->assertCreated(); // au-delà : déjà prévenu
        Notification::assertSentToTimes($this->merchantUser, ClientBillingAlert::class, 2);
        Notification::assertSentTo($this->merchantUser, ClientBillingAlert::class, fn ($n) => $n->title === '📦 Forfait du mois épuisé');
    }

    public function test_mad_client_sees_its_couriers_without_position(): void
    {
        $this->courierA->update(['is_available' => true]);
        $this->courierB->update(['is_available' => true]);
        $this->plan('mad_contract', ['courier_ids' => [$this->courierA->id, $this->courierB->id]]);
        CourierAbsence::create(['company_id' => $this->company->id, 'courier_id' => $this->courierB->id, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-11']);

        Sanctum::actingAs($this->merchantUser);
        $couriers = collect($this->getJson('/api/v1/merchant/space')->assertOk()->json('data.couriers'))->keyBy('name');

        $this->assertSame('available', $couriers['Koffi Ramasseur']['status']);
        $this->assertSame('absent', $couriers['Awa Livreuse']['status']);
        $this->assertNotNull($couriers['Koffi Ramasseur']['phone']);
        $this->assertSame(['id', 'name', 'phone', 'status', 'status_label'], array_keys($couriers['Koffi Ramasseur']));
    }

    public function test_invoices_are_notified_then_reminded(): void
    {
        Notification::fake();
        $this->plan();
        $this->send()->assertCreated();
        $this->company->update(['invoice_payment_info' => 'Wave : 07 00 00 00 00']);

        $this->travelTo('2026-12-01 08:00:00');
        $this->artisan('billing:invoice')->assertSuccessful();
        $invoice = ClientInvoice::firstOrFail();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/issue")->assertOk()
            ->assertJsonPath('data.payment_info', 'Wave : 07 00 00 00 00');
        Notification::assertSentTo($this->merchantUser, ClientBillingAlert::class, fn ($n) => $n->kind === 'invoice_issued');

        // Échéance le 16/12 : rien avant J-3, un rappel à J-3, puis le retard chaque semaine
        $this->travelTo('2026-12-10 09:10:00');
        $this->artisan('billing:remind')->expectsOutputToContain('0 rappel')->assertSuccessful();
        $this->travelTo('2026-12-13 09:10:00');
        $this->artisan('billing:remind')->expectsOutputToContain('1 rappel')->assertSuccessful();
        $this->artisan('billing:remind')->expectsOutputToContain('0 rappel')->assertSuccessful();
        $this->travelTo('2026-12-17 09:10:00');
        $this->artisan('billing:remind')->expectsOutputToContain('1 rappel')->assertSuccessful();
        $this->travelTo('2026-12-20 09:10:00');
        $this->artisan('billing:remind')->expectsOutputToContain('0 rappel')->assertSuccessful();
        $this->travelTo('2026-12-24 09:10:00');
        $this->artisan('billing:remind')->expectsOutputToContain('1 rappel')->assertSuccessful();

        Notification::assertSentTo($this->merchantUser, ClientBillingAlert::class, fn ($n) => $n->kind === 'invoice_overdue');

        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/merchant/space')->assertOk()
            ->assertJsonPath('data.invoices.to_pay', 50000)
            ->assertJsonPath('data.invoices.overdue', 50000);
    }

    public function test_the_company_chooses_its_payment_methods(): void
    {
        Sanctum::actingAs($this->admin);
        $url = "/api/v1/companies/{$this->company->id}";

        // IBAN obligatoire pour un compte bancaire, numéro pour le Mobile Money
        $this->patchJson($url, ['invoice_payment_methods' => [['method' => 'bank_transfer', 'enabled' => true, 'bank' => 'SGCI']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['invoice_payment_methods.0.iban' => 'Indiquez l\'IBAN du compte bancaire.']);

        $this->patchJson($url, ['invoice_payment_methods' => [
            ['method' => 'bank_transfer', 'enabled' => true, 'bank' => 'SGCI', 'holder' => 'Livraison Express', 'iban' => 'ci93 ci0000101234567890123456', 'bic' => 'sgci cici', 'phone' => 'ignoré'],
            ['method' => 'mobile_money', 'enabled' => true, 'operator' => 'Wave', 'phone' => '07 00 00 00 00'],
            ['method' => 'mobile_money', 'enabled' => false, 'operator' => 'Orange Money', 'phone' => '07 11 11 11 11'],
            ['method' => 'cheque', 'enabled' => true, 'payee' => 'Livraison Express CI'],
        ]])->assertOk()->assertJsonCount(4, 'data.invoice_payment_methods');

        $saved = $this->company->fresh()->invoice_payment_methods;
        $this->assertSame('CI93 CI00 0010 1234 5678 9012 3456', $saved[0]['iban']);
        $this->assertSame('SGCICICI', $saved[0]['bic']);
        $this->assertArrayNotHasKey('phone', $saved[0]);

        // Sur la facture : seulement les moyens proposés, dans l'ordre choisi
        $this->plan();
        $this->send()->assertCreated();
        $this->travelTo('2026-12-01 08:00:00');
        $this->artisan('billing:invoice')->assertSuccessful();
        Sanctum::actingAs($this->admin);
        $methods = $this->getJson('/api/v1/client-invoices/'.ClientInvoice::firstOrFail()->id)->assertOk()->json('data.payment_methods');

        $this->assertSame(['Virement bancaire', 'Wave', 'Chèque'], array_column($methods, 'title'));
        $this->assertSame(['label' => 'IBAN', 'value' => 'CI93 CI00 0010 1234 5678 9012 3456', 'copy' => true], $methods[0]['lines'][2]);
    }

    public function test_notification_settings_follow_the_activities(): void
    {
        Sanctum::actingAs($this->merchantUser);
        $events = fn () => array_column($this->getJson("/api/v1/merchants/{$this->merchant->id}/notifications")->assertOk()->json('data.events'), 'event');

        $this->assertContains('payout.paid', $events());
        $this->assertNotContains('invoice.issued', $events());

        $this->merchant->syncActivities([Activity::Distribution]);
        $this->assertNotContains('payout.paid', $events());
        $this->assertContains('invoice.issued', $events());
        $this->assertContains('forfait.usage', $events());
    }

    public function test_import_reads_activity_shipment_type_and_delay(): void
    {
        $this->plan('distribution_unit', ['delivery_delay' => '24h']);
        DistributionRate::create(['company_id' => $this->company->id, 'delivery_delay' => '48h', 'shipment_type' => 'pli', 'price' => 1200]);
        $this->merchant->syncActivities([Activity::Ecommerce, Activity::Distribution]);

        $content = "Téléphone;Commune;Montant;Frais payés par;Activité;Type d'envoi;Poids;Délai\n"
            ."0707070707;Yopougon;;;distribution;pli;;48h\n"
            ."0707070708;Yopougon;5000;moi;distribution;colis;2;24\n"
            ."0707070709;Yopougon;8000;destinataire;;;;\n"
            ."0707070710;Yopougon;;;mad;pli;;\n";

        Sanctum::actingAs($this->merchantUser);
        $rows = $this->post('/api/v1/orders/import', ['file' => UploadedFile::fake()->createWithContent('c.csv', $content), 'dry_run' => 1])
            ->assertOk()->assertJsonPath('data.valid', 2)->json('data.rows');

        $this->assertSame([], $rows[0]['errors']);
        $this->assertSame('distribution', $rows[0]['activity']);
        $this->assertStringContainsString('1 200 F', (string) $rows[0]['billing_label']);
        $this->assertSame(['Pas de montant à encaisser en Distribution'], $rows[1]['errors']);
        $this->assertSame('ecommerce', $rows[2]['activity']);
        $this->assertSame(['Activité MAD non ouverte pour ce client'], $rows[3]['errors']);

        $this->post('/api/v1/orders/import', ['file' => UploadedFile::fake()->createWithContent('c.csv', $content), 'skip_invalid' => 1])->assertCreated();
        $order = Order::where('recipient_phone', 'like', '%707070707')->firstOrFail();
        $this->assertSame(['distribution', 'pli', '48h', 1200], [$order->activity->value, $order->shipment_type->value, $order->delivery_delay->value, (int) $order->billing_amount]);
    }

    public function test_client_statistics_by_activity(): void
    {
        $this->plan();
        $this->merchant->syncActivities([Activity::Ecommerce, Activity::Distribution]);
        $this->send()->assertCreated();
        $this->send(['shipment_type' => 'colis'])->assertCreated();
        $this->send(['activity' => 'ecommerce', 'shipment_type' => null, 'items_amount' => 1000, 'fee_payer' => 'recipient'])->assertCreated();

        Sanctum::actingAs($this->merchantUser);
        $all = $this->getJson('/api/v1/merchant/stats?from=2026-11-01&to=2026-11-10')->assertOk()->json('data');
        $this->assertSame(3, $all['kpis']['created']);

        $distribution = $this->getJson('/api/v1/merchant/stats?from=2026-11-01&to=2026-11-10&activity=distribution')->assertOk()->json('data');
        $this->assertSame(2, $distribution['kpis']['created']);
        $this->assertSame(2, $distribution['shipments']['total']);
        $this->assertSame([1, 1, 0], array_column($distribution['shipments']['types'], 'count'));
        $this->assertSame(2, $distribution['shipments']['destinations'][0]['count']);

        $this->assertNull($this->getJson('/api/v1/merchant/stats?from=2026-11-01&to=2026-11-10&activity=ecommerce')->json('data.shipments'));
    }
}
