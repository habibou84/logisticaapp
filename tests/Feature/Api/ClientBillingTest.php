<?php

namespace Tests\Feature\Api;

use App\Enums\Activity;
use App\Enums\ClientInvoiceStatus;
use App\Enums\OrderStatus;
use App\Models\BillingPlan;
use App\Models\ClientInvoice;
use App\Models\DistributionRate;
use App\Models\InteriorRate;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\Zone;
use App\Services\Assignment\AutoAssigner;
use App\Services\Finance\FinanceRecorder;
use App\Services\Orders\ActivityOptions;
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
        // Contrat sans zone choisie : les communes « Forfait » ; l'intérieur est facturé à part
        $plan = $this->plan();
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
        // 50 000 F pour 3 courses, zones couvertes : Yopougon et Bouaké ; au-delà : 800 F à Abidjan, tarif de la ville à l'intérieur
        InteriorRate::create(['company_id' => $this->company->id, 'city' => 'Bouaké', 'delivery_delay' => '48h', 'pli_price' => 3000, 'colis_price' => 4000, 'extra_kg_price' => 500]);
        $bouake = Zone::factory()->create(['company_id' => $this->company->id, 'name' => 'Bouaké', 'city' => 'Bouaké']);
        $plan = $this->plan(['zone_ids' => [$this->yopougon->id, $bouake->id]]);
        $this->assertEqualsCanonicalizing([$this->yopougon->id, $bouake->id], $plan->zones()->pluck('zones.id')->all());

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
        $this->assertSame(['included', 'Course(s) dans les zones du forfait : 5 (3 inclus)', 5, 0], $lines[1]);
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
        $this->assertSame([], $data[0]['zones']);
        $this->assertSame($this->merchant->business_name, $data[0]['merchant']['business_name']);
        $this->assertSame([2, 3, 67, 50000], [$data[0]['usage']['used'], $data[0]['usage']['included'], $data[0]['usage']['percent'], $data[0]['usage']['estimate']]);

        $this->assertCount(0, $this->getJson('/api/v1/billing-plans?state=ended')->assertOk()->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/billing-plans?activity=mad')->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/billing-plans?search='.urlencode(mb_substr($this->merchant->business_name, 0, 4)))->json('data'));

        // Le client ne voit pas la liste des contrats
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/billing-plans')->assertForbidden();
    }

    public function test_negotiated_rates_replace_the_common_grid_for_one_client(): void
    {
        $this->plan();
        $bouake = Zone::factory()->create(['company_id' => $this->company->id, 'name' => 'Bouaké', 'city' => 'Bouaké']);
        InteriorRate::create(['company_id' => $this->company->id, 'city' => 'Bouaké', 'delivery_delay' => '48h', 'pli_price' => 3000, 'colis_price' => 4000, 'extra_kg_price' => 500]);

        // Prix négociés du client : saisis puis importés dans sa grille
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/interior-rates', ['merchant_id' => $this->merchant->id, 'city' => 'bouaké', 'delivery_delay' => '48h', 'pli_price' => 2000, 'colis_price' => 2500])->assertCreated();
        $csv = "Ville;Délai;Pli;Colis;Kg supplémentaire\nKorhogo;48h;3500;4500;600\n";
        $this->post('/api/v1/interior-rates/import', ['merchant_id' => $this->merchant->id, 'file' => UploadedFile::fake()->createWithContent('t.csv', $csv)])->assertOk()->assertJsonPath('data.created', 1);

        $this->assertSame(['Bouaké'], array_column($this->getJson('/api/v1/interior-rates')->json('data'), 'city'));
        $this->assertSame(['Bouaké', 'Korhogo'], array_column($this->getJson('/api/v1/interior-rates?merchant_id='.$this->merchant->id)->json('data'), 'city'));
        $this->getJson('/api/v1/client-rates/clients')->assertOk()
            ->assertJsonPath('data.0.id', $this->merchant->id)->assertJsonPath('data.0.interior_rates', 2);

        // Ce client : prix négociés ; un autre client : grille commune
        $order = $this->createOrder(['shipment_type' => 'colis', 'delivery_zone_id' => $bouake->id]);
        $this->assertSame(['interior', 2500], [$order->billing_basis, (int) $order->billing_amount]);

        $other = Merchant::factory()->create(['company_id' => $this->company->id, 'pickup_zone_id' => $this->cocody->id]);
        $this->postJson("/api/v1/merchants/{$other->id}/billing-plans", ['formula' => 'distribution_contract', 'monthly_fee' => 10000, 'included_quantity' => 1, 'delivery_delay' => '48h', 'starts_on' => '2026-11-01'])->assertCreated();
        $other->syncActivities([Activity::Distribution]);
        $otherOrder = $this->createOrder(['shipment_type' => 'colis', 'delivery_zone_id' => $bouake->id], $this->admin, $other);
        $this->assertSame(4000, (int) $otherOrder->billing_amount);

        // Un client ne voit aucune grille
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/interior-rates?merchant_id='.$this->merchant->id)->assertForbidden();
    }

    public function test_supplements_go_on_the_month_invoice_and_are_frozen_once_issued(): void
    {
        $this->plan();
        $order = $this->createOrder(['shipment_type' => 'pli']);

        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/client-supplements', [
            'merchant_id' => $this->merchant->id, 'activity' => 'distribution', 'label' => 'Livraison urgente le dimanche',
            'unit_price' => 5000, 'order_id' => $order->id, 'performed_on' => '2026-11-15',
        ])->assertCreated()->assertJsonPath('data.amount', 5000)->assertJsonPath('data.order.tracking_code', $order->tracking_code)->json('data.id');
        $this->postJson('/api/v1/client-supplements', [
            'merchant_id' => $this->merchant->id, 'activity' => 'distribution', 'label' => 'Mise sous pli', 'quantity' => 200, 'unit_price' => 25,
        ])->assertCreated();
        // Activité que le client n'a pas
        $this->postJson('/api/v1/client-supplements', ['merchant_id' => $this->merchant->id, 'activity' => 'mad', 'label' => 'X', 'unit_price' => 1])->assertUnprocessable();

        // Le client voit ses suppléments du mois, sans pouvoir en ajouter
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/client-supplements?month=2026-11')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 10000);
        $this->postJson('/api/v1/client-supplements', ['merchant_id' => $this->merchant->id, 'activity' => 'distribution', 'label' => 'X', 'unit_price' => 1])->assertForbidden();
        $this->assertSame(10000, $this->getJson('/api/v1/merchant/space')->json('data.plans.0.supplements'));

        // Facture du mois : section « Suppléments »
        $this->artisan('billing:invoice', ['--month' => '2026-11'])->assertSuccessful();
        $invoice = ClientInvoice::sole();
        $supplements = $invoice->lines->where('kind', 'supplement')->values();
        $this->assertSame(['Supplément · Livraison urgente le dimanche (15/11)', 'Supplément · Mise sous pli (10/11)'], $supplements->pluck('label')->sort()->values()->all());
        $this->assertSame(50000 + 10000, $invoice->total);

        // Émise : supplément figé, le mois est fermé
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/issue")->assertOk();
        $this->patchJson("/api/v1/client-supplements/{$id}", ['unit_price' => 1])->assertUnprocessable();
        $this->deleteJson("/api/v1/client-supplements/{$id}")->assertUnprocessable();
        $this->postJson('/api/v1/client-supplements', ['merchant_id' => $this->merchant->id, 'activity' => 'distribution', 'label' => 'Oubli', 'unit_price' => 1000, 'performed_on' => '2026-11-20'])
            ->assertUnprocessable()->assertJsonPath('message', 'La facture de ce mois est déjà émise : datez le supplément du mois suivant.');
        $this->getJson('/api/v1/client-supplements?month=2026-11')->assertJsonPath('data.0.locked', true);
    }

    public function test_distribution_lots_are_quick_contracts_billed_on_the_month_invoice(): void
    {
        DistributionRate::create(['company_id' => $this->company->id, 'delivery_delay' => '48h', 'shipment_type' => 'pli', 'price' => 1500]);
        $this->merchant->syncActivities([Activity::Distribution]);
        Sanctum::actingAs($this->admin);
        $lot = fn (array $data) => $this->postJson("/api/v1/merchants/{$this->merchant->id}/billing-plans", [
            'formula' => 'distribution_lot', 'delivery_delay' => '48h', 'starts_on' => '2026-11-10', ...$data,
        ])->assertCreated()->json('data');

        // Lot A : 3 courriers pour 10 000 F
        $a = $lot(['lot_label' => 'Vœux 2027', 'lot_quantity' => 3, 'lot_price' => 10000]);
        $this->assertStringContainsString('Vœux 2027 : 3 colis en 48 h pour 10 000 F', $a['summary']);
        // Seul lot ouvert et pas de contrat : choisi d'office
        $orders = collect(range(1, 4))->map(fn () => $this->createOrder(['shipment_type' => 'pli', 'activity' => 'distribution']));
        $this->assertSame(['lot', 'lot', 'lot', 'lot_extra'], $orders->pluck('billing_basis')->all());
        $this->assertSame([0, 0, 0, 1500], $orders->map(fn ($o) => (int) $o->billing_amount)->all());
        $this->assertSame($a['id'], $orders->first()->billing_plan_id);

        // Lot B en parallèle, au prix de la grille : il faut choisir le lot
        $b = $lot(['lot_quantity' => 2]);
        $this->postJson('/api/v1/orders', ['merchant_id' => $this->merchant->id, 'activity' => 'distribution', 'recipient_phone' => '0707070707', 'delivery_zone_id' => $this->yopougon->id, 'shipment_type' => 'pli'])
            ->assertUnprocessable()->assertJsonPath('message', 'Ce client a plusieurs lots en cours : choisissez le lot de la course.');
        $inB = $this->postJson('/api/v1/orders', ['merchant_id' => $this->merchant->id, 'activity' => 'distribution', 'billing_plan_id' => $b['id'], 'recipient_phone' => '0707070707', 'delivery_zone_id' => $this->yopougon->id, 'shipment_type' => 'pli'])
            ->assertCreated()->json('data.id');
        $this->assertSame(['lot', 1500], [Order::find($inB)->billing_basis, (int) Order::find($inB)->billing_amount]);

        // Un contrat mensuel s'ajoute sans arrêter les lots
        $this->postJson("/api/v1/merchants/{$this->merchant->id}/billing-plans", ['formula' => 'distribution_contract', 'monthly_fee' => 20000, 'included_quantity' => 10, 'delivery_delay' => '48h', 'starts_on' => '2026-11-10'])->assertCreated();
        $this->assertCount(2, BillingPlan::openLotsFor($this->merchant->fresh()));
        $contracts = collect($this->getJson('/api/v1/billing-plans')->json('data'))->keyBy('id');
        $this->assertSame([4, 3, 1, 0], [$contracts[$a['id']]['usage']['used'], $contracts[$a['id']]['usage']['included'], $contracts[$a['id']]['usage']['over'], $contracts[$a['id']]['usage']['delivered']]);
        $this->assertSame(11500, $contracts[$a['id']]['usage']['estimate']);
        $this->assertSame('Vœux 2027', $contracts[$a['id']]['lot_name']);
        // Avec le contrat, une course sans lot va au contrat
        $this->assertSame('forfait', $this->createOrder(['shipment_type' => 'pli', 'activity' => 'distribution'])->billing_basis);

        // Le client voit l'avancement de ses lots et peut les choisir sur ses courses
        Sanctum::actingAs($this->merchantUser);
        $space = $this->getJson('/api/v1/merchant/space')->assertOk()->json('data.lots');
        $this->assertSame([$a['id'], $b['id']], array_column($space, 'id'));
        $this->assertSame(['Vœux 2027', 3, 4, 1], [$space[0]['lot_name'], $space[0]['lot_quantity'], $space[0]['used'], $space[0]['over']]);
        $me = $this->getJson('/api/v1/auth/me')->assertOk()->json();
        $this->assertCount(2, data_get($me, 'data.merchant.lots', data_get($me, 'merchant.lots', data_get($me, 'user.merchant.lots'))));
        Sanctum::actingAs($this->admin);

        // Facture du mois : prix du lot, colis compris, colis en plus, lot B à l'unité, forfait du contrat
        $this->artisan('billing:invoice', ['--month' => '2026-11'])->assertSuccessful();
        $lines = ClientInvoice::sole()->lines->map(fn ($l) => [$l->kind, $l->label, $l->quantity, $l->unit_price])->values();
        $this->assertContains(['lot', 'Vœux 2027 : 3 colis en 48 h (prix du lot)', 1, 10000], $lines->all());
        $this->assertContains(['included', 'Vœux 2027 · compris dans le prix du lot', 3, 0], $lines->all());
        $this->assertContains(['lot_extra', 'Vœux 2027 · au-delà des 3 colis · Pli en 48 h', 1, 1500], $lines->all());
        $this->assertContains(['lot', "Lot n° {$b['id']} · Pli en 48 h", 1, 1500], $lines->all());
        $this->assertSame(10000 + 1500 + 1500 + 20000, ClientInvoice::sole()->total);
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
        ])->assertUnprocessable()->assertJsonFragment(['message' => 'Prix à l\'unité d\'un Pli en 48 h pas encore fixé : voir Distribution › Tarifs.']);

        // Devis pendant la saisie : rien sur la course, explication de la facture
        $this->postJson('/api/v1/quotes', ['delivery_zone_id' => $this->yopougon->id, 'shipment_type' => 'pli', 'delivery_delay' => '24h'])
            ->assertOk()->assertJsonPath('data.total', 0)->assertJsonPath('data.billing.amount', 1500);

        // Pas de forfait : seulement les envois à l'unité
        $this->travelTo('2026-12-01 08:00:00');
        $this->artisan('billing:invoice')->assertSuccessful();
        $this->assertSame(5400, ClientInvoice::sole()->total);
        $this->assertSame(['unit'], ClientInvoice::sole()->lines->pluck('kind')->unique()->values()->all());
    }

    public function test_several_plis_or_cheques_on_one_course_count_one_by_one_or_once(): void
    {
        // Forfait de 3 plis : une course de 5 plis en compte 5 (3 inclus, 2 au-delà) ; 4 chèques facturés 4 fois
        $plan = $this->plan();
        $plis = $this->createOrder(['shipment_type' => 'pli', 'shipment_quantity' => 5]);
        $cheques = $this->createOrder(['shipment_type' => 'cheque', 'shipment_quantity' => 4]);
        // Colis : toujours un envoi
        $colis = $this->createOrder(['shipment_type' => 'colis', 'shipment_quantity' => 3]);
        $this->assertSame([5, 5, 'forfait'], [$plis->shipment_quantity, $plis->billing_units, $plis->billing_basis]);
        $this->assertSame([4, 6000], [$cheques->billing_units, $cheques->billing_amount]);
        $this->assertSame(1, $colis->billing_units);

        Sanctum::actingAs($this->merchantUser);
        $this->getJson("/api/v1/orders/{$plis->id}")->assertOk()->assertJsonPath('data.package.shipment_quantity', 5);
        $this->postJson('/api/v1/quotes', ['delivery_zone_id' => $this->yopougon->id, 'shipment_type' => 'cheque', 'shipment_quantity' => 2])
            ->assertOk()->assertJsonPath('data.billing.units', 2)->assertJsonPath('data.billing.amount', 3000);

        // Réglage du contrat : la remise compte une fois
        $plan->update(['options' => ['quantity_billing' => 'per_course']]);
        app(ActivityOptions::class)->forget();
        $once = $this->createOrder(['shipment_type' => 'cheque', 'shipment_quantity' => 10]);
        $this->assertSame([10, 1, 1500], [$once->shipment_quantity, $once->billing_units, $once->billing_amount]);

        $this->travelTo('2026-12-01 08:00:00');
        $this->artisan('billing:invoice')->assertSuccessful();
        $lines = ClientInvoice::sole()->lines->map(fn ($l) => [$l->kind, $l->quantity, $l->unit_price, $l->amount])->all();
        $this->assertSame([
            ['forfait', 1, 50000, 50000],
            ['included', 6, 0, 0],
            ['overage', 3, 800, 2400],
            ['cheque', 5, 1500, 7500],
        ], $lines);
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
        $this->assertStringStartsWith('Course(s) dans les zones du forfait : 2', ClientInvoice::sole()->lines->firstWhere('kind', 'included')->label);
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
