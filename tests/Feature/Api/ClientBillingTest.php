<?php

namespace Tests\Feature\Api;

use App\Enums\ClientInvoiceStatus;
use App\Enums\OrderStatus;
use App\Models\BillingPlan;
use App\Models\ClientInvoice;
use App\Models\DistributionRate;
use App\Models\InteriorRate;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\Zone;
use App\Services\Assignment\AutoAssigner;
use App\Services\Finance\FinanceRecorder;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Formules des clients : distribution (forfait, unité), mise à disposition (MAD), tarifs de l'intérieur,
 * factures du mois.
 */
class ClientBillingTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private Zone $bingerville;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo('2026-11-10 10:00:00');
        foreach ([$this->cocody, $this->yopougon, $this->plateau] as $zone) {
            $zone->update(['in_forfait' => true]);
        }
        $this->bingerville = $this->zone('Bingerville');
        $this->rule($this->grid, $this->cocody, $this->bingerville, 2500);
    }

    private function plan(array $attributes = []): BillingPlan
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson("/api/v1/merchants/{$this->merchant->id}/billing-plans", [
            'formula' => 'distribution_contract',
            'monthly_fee' => 50000,
            'included_quantity' => 3,
            'overage_price' => 800,
            'delivery_delay' => '48h',
            'cheque_price' => 1500,
            'extra_kg_price' => 300,
            'starts_on' => '2026-11-01',
            ...$attributes,
        ])->assertCreated()->json('data.id');
        $plan = BillingPlan::findOrFail($id);
        // Client de cette seule activité : ses courses en relèvent sans avoir à la choisir
        $this->merchant->syncActivities([$plan->activity]);

        return $plan;
    }

    public function test_contract_courses_are_billed_on_the_monthly_invoice(): void
    {
        // Ancienne règle : l'intérieur toujours facturé à part (hors forfait)
        $plan = $this->plan(['interior_in_forfait' => false]);
        InteriorRate::create(['company_id' => $this->company->id, 'city' => 'Bouaké', 'delivery_delay' => '48h', 'pli_price' => 3000, 'colis_price' => 4000, 'extra_kg_price' => 500]);
        $bouake = Zone::factory()->create(['company_id' => $this->company->id, 'name' => 'Bouaké', 'city' => 'Bouaké']);

        // Quatre envois dans les communes du forfait (trois inclus), dont un colis de 7,2 kg
        $first = $this->createOrder(['shipment_type' => 'pli']);
        $this->createOrder(['shipment_type' => 'colis', 'delivery_zone_id' => $this->angre->id]);
        $this->createOrder(['shipment_type' => 'colis', 'weight_kg' => 7.2]);
        $this->createOrder(['shipment_type' => 'pli', 'delivery_zone_id' => $this->plateau->id]);
        // Hors forfait : chèque, intérieur (colis de 6 kg), commune hors forfait, course annulée
        $this->createOrder(['shipment_type' => 'cheque']);
        $interior = $this->createOrder(['shipment_type' => 'colis', 'weight_kg' => 6, 'delivery_zone_id' => $bouake->id]);
        $this->createOrder(['delivery_zone_id' => $this->bingerville->id]);
        $this->createOrder()->forceFill(['status' => OrderStatus::Cancelled])->save();

        // Rien n'est retenu sur la course : délai du contrat, base de facturation
        $this->assertSame([0, 0, '48h', 'forfait', $plan->id], [$first->delivery_fee, $first->totalFees(), $first->delivery_delay->value, $first->billing_basis, $first->billing_plan_id]);
        $this->assertSame(['interior', 4500], [$interior->billing_basis, $interior->billing_amount]);
        $this->getJson("/api/v1/orders/{$first->id}")->assertOk()
            ->assertJsonPath('data.amounts.total_fees', 0)
            ->assertJsonPath('data.billing.basis', 'forfait')
            ->assertJsonPath('data.package.shipment_type', 'pli');

        // Livrée : aucun frais au grand livre du client
        app(FinanceRecorder::class)->onTransition($first->fresh(), OrderStatus::Delivered, null, ['collected_amount' => 10000], $this->admin);
        $this->assertSame(0, (int) MerchantLedgerEntry::where('type', 'delivery_fee')->count());

        // Facture de novembre : forfait, dépassement, poids, chèque, intérieur, hors forfait
        $this->travelTo('2026-12-01 08:00:00');
        $this->artisan('billing:invoice')->assertSuccessful();
        $invoice = ClientInvoice::sole();
        $lines = $invoice->lines->map(fn ($l) => [$l->kind, $l->quantity, $l->unit_price, $l->amount])->all();
        $this->assertSame([
            ['forfait', 1, 50000, 50000],
            ['included', 4, 0, 0],
            ['overage', 1, 800, 800],
            ['extra_kg', 3, 300, 900],
            ['cheque', 1, 1500, 1500],
            ['interior', 1, 4500, 4500],
            ['outside', 1, 2500, 2500],
        ], $lines);
        $this->assertSame(60200, $invoice->total);
        $this->assertSame(ClientInvoiceStatus::Draft, $invoice->status);

        Sanctum::actingAs($this->admin);
        $detail = $this->getJson("/api/v1/client-invoices/{$invoice->id}")->assertOk();
        $this->assertCount(7, $detail->json('data.orders'));

        // Remise ajoutée à la main : gardée au recalcul
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/lines", ['label' => 'Remise fidélité', 'quantity' => 1, 'unit_price' => -1200])
            ->assertOk()->assertJsonPath('data.total', 59000);
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/refresh")->assertOk()->assertJsonPath('data.total', 59000);

        // Émission : numéro, échéance, plus de modification
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/issue")->assertOk()
            ->assertJsonPath('data.number', 'DIS-202611-0001')
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.due_at', '2026-12-16');
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/lines", ['label' => 'X', 'quantity' => 1, 'unit_price' => 10])->assertUnprocessable();
        $this->artisan('billing:invoice', ['--month' => '2026-11'])->assertSuccessful();
        $this->assertSame(59000, $invoice->fresh()->total);

        // Paiements : partiel, puis le reste ; jamais plus que le reste à payer
        $pay = fn (int $amount) => $this->postJson("/api/v1/client-invoices/{$invoice->id}/payments", ['amount' => $amount, 'method' => 'bank_transfer', 'paid_on' => '2026-12-01']);
        $pay(20000)->assertOk()->assertJsonPath('data.status', 'issued')->assertJsonPath('data.balance', 39000);
        $pay(40000)->assertUnprocessable();
        $pay(39000)->assertOk()->assertJsonPath('data.status', 'paid');
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/void")->assertUnprocessable();
    }

    public function test_interior_courses_count_in_the_forfait_then_cost_their_city_price(): void
    {
        // 50 000 F pour 3 courses (Abidjan ou intérieur) ; au-delà : 800 F à Abidjan, tarif de la ville à l'intérieur
        $plan = $this->plan();
        $this->assertTrue($plan->countsInterior());
        InteriorRate::create(['company_id' => $this->company->id, 'city' => 'Bouaké', 'delivery_delay' => '48h', 'pli_price' => 3000, 'colis_price' => 4000, 'extra_kg_price' => 500]);
        $bouake = Zone::factory()->create(['company_id' => $this->company->id, 'name' => 'Bouaké', 'city' => 'Bouaké']);

        $this->createOrder(['shipment_type' => 'pli']);
        $interior = $this->createOrder(['shipment_type' => 'colis', 'delivery_zone_id' => $bouake->id, 'weight_kg' => 6.5]);
        $this->createOrder(['shipment_type' => 'colis']);
        $this->createOrder(['shipment_type' => 'pli']);                                          // 4e : au-delà, Abidjan
        $this->createOrder(['shipment_type' => 'colis', 'delivery_zone_id' => $bouake->id]);    // 5e : au-delà, Bouaké

        // Dans le forfait : seulement les kilos en plus, au prix de la ville (2 kg × 500 F)
        $this->assertSame(['forfait', 1000], [$interior->billing_basis, (int) $interior->billing_amount]);
        $this->assertSame(4000, $interior->pricing_details['billing']['overage']);

        $this->artisan('billing:invoice', ['--month' => '2026-11'])->assertSuccessful();
        $invoice = ClientInvoice::sole();
        $lines = $invoice->lines->sortBy('position')->map(fn ($l) => [$l->kind, $l->label, $l->quantity, $l->unit_price])->values()->all();

        $this->assertSame(['forfait', 1, 50000], [$lines[0][0], $lines[0][2], $lines[0][3]]);
        $this->assertSame(['included', 'Course(s) à Abidjan et à l\'intérieur : 5 (3 inclus)', 5, 0], $lines[1]);
        $this->assertSame(['overage', 'Course(s) au-delà du forfait · Abidjan', 1, 800], $lines[2]);
        $this->assertSame(['overage', 'Course(s) au-delà du forfait · Colis pour Bouaké en 48 h', 1, 4000], $lines[3]);
        $this->assertSame('extra_kg', $lines[4][0]);
        $this->assertSame(50000 + 800 + 4000 + 1000, $invoice->total);
    }

    public function test_contracts_page_lists_contracts_with_their_month(): void
    {
        $plan = $this->plan();
        $this->createOrder(['shipment_type' => 'pli']);
        $this->createOrder(['shipment_type' => 'pli']);

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/billing-plans')->assertOk()->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($plan->id, $data[0]['id']);
        $this->assertSame('current', $data[0]['state']);
        $this->assertTrue($data[0]['interior_in_forfait']);
        $this->assertSame($this->merchant->business_name, $data[0]['merchant']['business_name']);
        $this->assertSame([2, 3, 67, 50000], [$data[0]['usage']['used'], $data[0]['usage']['included'], $data[0]['usage']['percent'], $data[0]['usage']['estimate']]);

        $this->assertCount(0, $this->getJson('/api/v1/billing-plans?state=ended')->assertOk()->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/billing-plans?activity=mad')->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/billing-plans?search='.urlencode(mb_substr($this->merchant->business_name, 0, 4)))->json('data'));

        // Le client ne voit pas la liste des contrats
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/billing-plans')->assertForbidden();
    }

    public function test_unit_distribution_follows_the_chosen_delay(): void
    {
        $this->plan(['formula' => 'distribution_unit', 'delivery_delay' => '24h', 'extra_kg_price' => 200]);
        foreach ([['24h', 'pli', 1500], ['24h', 'colis', 2000], ['72h', 'colis', 1200]] as [$delay, $type, $price]) {
            DistributionRate::create(['company_id' => $this->company->id, 'delivery_delay' => $delay, 'shipment_type' => $type, 'price' => $price]);
        }

        $fast = $this->createOrder(['shipment_type' => 'colis', 'weight_kg' => 5.5]);
        $slow = $this->createOrder(['shipment_type' => 'colis', 'delivery_delay' => '72h']);
        // Pli de plus de 500 g : facturé comme un colis
        $heavyPli = $this->createOrder(['shipment_type' => 'pli', 'weight_kg' => 0.8]);
        $this->assertSame(['unit', 2200, '24h'], [$fast->billing_basis, $fast->billing_amount, $fast->delivery_delay->value]);
        $this->assertSame(1200, $slow->billing_amount);
        $this->assertSame(['colis', 2000], [$heavyPli->shipment_type->value, $heavyPli->billing_amount]);

        // Prix pas encore fixé : la course est refusée avec une explication
        Sanctum::actingAs($this->merchantUser);
        $this->postJson('/api/v1/orders', [
            'recipient_phone' => '0707070707', 'delivery_zone_id' => $this->yopougon->id, 'fee_payer' => 'merchant',
            'shipment_type' => 'pli', 'delivery_delay' => '48h',
        ])->assertUnprocessable()->assertJsonFragment(['message' => 'Prix à l\'unité d\'un Pli en 48 h pas encore fixé : voir Tarifs › Distribution à l\'unité.']);

        // Devis pendant la saisie : rien sur la course, explication de la facture
        $this->postJson('/api/v1/quotes', ['delivery_zone_id' => $this->yopougon->id, 'shipment_type' => 'pli', 'delivery_delay' => '24h'])
            ->assertOk()->assertJsonPath('data.total', 0)->assertJsonPath('data.billing.amount', 1500);

        // Pas de forfait : seulement les envois à l'unité
        $this->travelTo('2026-12-01 08:00:00');
        $this->artisan('billing:invoice')->assertSuccessful();
        $this->assertSame(5400, ClientInvoice::sole()->total);
        $this->assertSame(['unit'], ClientInvoice::sole()->lines->pluck('kind')->unique()->values()->all());
    }

    public function test_interior_rates_are_imported_with_their_cities(): void
    {
        Sanctum::actingAs($this->admin);
        $csv = "Ville;Délai;Pli;Colis;Kg supplémentaire\nBouaké;24h;3 000;4000;500\nbouake;48 h;2500;3500;500\nSan-Pédro;72h;2000;3000;\nAbidjan;24h;1;1;1\nKorhogo;;1000;1000;0\n";
        $file = fn () => UploadedFile::fake()->createWithContent('tarifs.csv', $csv);

        $preview = $this->post('/api/v1/interior-rates/import', ['file' => $file(), 'dry_run' => true], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame([3, ['Bouaké', 'San-Pédro'], 2], [$preview->json('data.created'), $preview->json('data.zones_created'), count($preview->json('data.errors'))]);
        $this->assertSame(0, InteriorRate::count());

        $this->post('/api/v1/interior-rates/import', ['file' => $file()], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(3, InteriorRate::count());
        $this->assertSame(['pli_price' => 3000, 'colis_price' => 4000], InteriorRate::where('delivery_delay', '24h')->first()->only(['pli_price', 'colis_price']));
        $this->assertTrue(Zone::where('city', 'San-Pédro')->exists());

        // Relancé : mis à jour, sans doublon
        $this->post('/api/v1/interior-rates/import', ['file' => $file()], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.updated', 3);
        $this->assertSame(3, InteriorRate::count());
        $this->assertSame(1, Zone::where('city', 'Bouaké')->count());

        // Ville sans tarif pour le délai du contrat : la course l'explique
        $this->plan(['delivery_delay' => '72h']);
        Sanctum::actingAs($this->merchantUser);
        $this->postJson('/api/v1/orders', [
            'recipient_phone' => '0707070707', 'delivery_zone_id' => Zone::where('city', 'Bouaké')->value('id'), 'fee_payer' => 'merchant',
        ])->assertUnprocessable()->assertJsonFragment(['message' => 'Aucun tarif pour Bouaké en 72 h : ajoutez-le dans Distribution › Tarifs (villes de l\'intérieur).']);

        // Réservé aux administrateurs
        Sanctum::actingAs($this->dispatcher);
        $this->post('/api/v1/interior-rates/import', ['file' => $file()], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_mad_courses_go_to_the_dedicated_couriers(): void
    {
        $this->courierB->update(['is_available' => true]);
        $this->plan(['formula' => 'mad_contract', 'courier_ids' => [$this->courierB->id]]);
        $workflow = app(OrderWorkflow::class);

        // Assignation automatique désactivée : le livreur attitré reçoit quand même le ramassage
        $order = $this->createOrder();
        $workflow->transition($this->dispatcher, $order, OrderStatus::Confirmed);
        $order->refresh();
        $this->assertSame([OrderStatus::PickupAssigned, $this->courierB->id, 'mad'], [$order->status, $order->pickup_courier_id, $order->assignments()->sole()->source]);

        // Ramassé : il garde la livraison
        $order->forceFill(['status' => OrderStatus::PickedUp])->save();
        $this->assertNotNull(app(AutoAssigner::class)->delivery($order->fresh()));
        $this->assertSame($this->courierB->id, $order->fresh()->delivery_courier_id);

        // Livreur attitré indisponible : la course reste à l'équipe
        $this->courierB->update(['is_available' => false]);
        $other = $this->createOrder();
        $workflow->transition($this->dispatcher, $other, OrderStatus::Confirmed);
        $this->assertSame(OrderStatus::Confirmed, $other->fresh()->status);

        // MAD : on compte les courses
        $this->travelTo('2026-12-01 08:00:00');
        $this->artisan('billing:invoice')->assertSuccessful();
        $this->assertStringStartsWith('Course(s) sur les communes du forfait : 2', ClientInvoice::sole()->lines->firstWhere('kind', 'included')->label);
    }

    public function test_a_new_plan_ends_the_previous_one_and_used_plans_keep_their_prices(): void
    {
        $plan = $this->plan();
        $this->createOrder();

        $this->patchJson("/api/v1/billing-plans/{$plan->id}", ['monthly_fee' => 60000])->assertUnprocessable();
        $this->patchJson("/api/v1/billing-plans/{$plan->id}", ['notes' => 'Contrat signé'])->assertOk();
        $this->deleteJson("/api/v1/billing-plans/{$plan->id}")->assertUnprocessable();

        $next = $this->plan(['monthly_fee' => 60000, 'starts_on' => '2026-11-15']);
        $this->assertSame('2026-11-14', $plan->fresh()->ends_on->toDateString());
        $this->getJson("/api/v1/merchants/{$this->merchant->id}")->assertOk()->assertJsonPath('data.billing_plans.0.id', $plan->id);
        $this->travelTo('2026-11-15 09:00:00');
        $this->assertSame($next->id, $this->createOrder()->billing_plan_id);

        // Le dispatcher ne gère pas les formules
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/merchants/{$this->merchant->id}/billing-plans", ['formula' => 'distribution_unit', 'delivery_delay' => '24h', 'starts_on' => '2026-12-01'])->assertForbidden();
    }

    public function test_the_client_sees_only_its_issued_invoices(): void
    {
        $this->plan();
        $this->createOrder();
        $this->travelTo('2026-12-01 08:00:00');
        $this->artisan('billing:invoice')->assertSuccessful();
        $invoice = ClientInvoice::sole();

        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/client-invoices')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/client-invoices/{$invoice->id}")->assertNotFound();

        $invoice->update(['status' => ClientInvoiceStatus::Issued, 'number' => 'DIS-202611-0001']);
        $this->getJson('/api/v1/client-invoices')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('totals', null);
        $this->getJson("/api/v1/client-invoices/{$invoice->id}")->assertOk()->assertJsonPath('data.total', 50000);
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/payments", ['amount' => 1, 'method' => 'cash', 'paid_on' => '2026-12-01'])->assertForbidden();

        // Sans formule : courses ordinaires, frais sur la course
        $other = Merchant::factory()->create(['company_id' => $this->company->id, 'pickup_zone_id' => $this->cocody->id]);
        $ordinary = $this->createOrder([], $this->admin, $other);
        $this->assertSame([1500, null], [$ordinary->delivery_fee, $ordinary->billing_basis]);
    }
}
