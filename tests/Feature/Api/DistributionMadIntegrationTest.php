<?php

namespace Tests\Feature\Api;

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Models\BillingPlan;
use App\Models\ClientInvoice;
use App\Models\CourierEarning;
use App\Models\CourierRule;
use App\Models\DistributionRate;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\PayPlan;
use App\Services\Finance\CourierPay;
use App\Services\Orders\OrderDispatcher;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Distribution et MAD dans les opérations, au choix de l'administrateur : réservation des livreurs en MAD,
 * preuve de remise, messages sans montant (réglages de l'entreprise, que chaque contrat peut changer).
 */
class DistributionMadIntegrationTest extends TestCase
{
    use BuildsDeliveryWorld;
    use RefreshDatabase;

    private const MONDAY = '2026-11-23';

    private Merchant $madClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo(self::MONDAY.' 10:00:00');
        foreach ([$this->cocody, $this->yopougon, $this->plateau] as $zone) {
            $zone->update(['in_forfait' => true]);
        }
        $this->merchant->syncActivities(['ecommerce', 'distribution']);
        $this->merchant->update(['whatsapp_phone' => '0501020304']);
        $this->madClient = Merchant::factory()->create(['company_id' => $this->company->id, 'business_name' => 'Ivoire Assurances', 'pickup_zone_id' => $this->plateau->id]);
        $this->madClient->syncActivities(['mad']);
        foreach ([$this->courierA, $this->courierB] as $courier) {
            $courier->update(['is_available' => true]);
        }
    }

    private function plan(Merchant $merchant, array $attributes): BillingPlan
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson("/api/v1/merchants/{$merchant->id}/billing-plans", [
            'monthly_fee' => 50000,
            'included_quantity' => 50,
            'overage_price' => 800,
            'delivery_delay' => '48h',
            'starts_on' => '2026-11-01',
            ...$attributes,
        ])->assertCreated()->json('data.id');

        return BillingPlan::findOrFail($id);
    }

    private function confirm(Order $order): Order
    {
        app(OrderWorkflow::class)->transition($this->dispatcher, $order, OrderStatus::Confirmed);

        return $order->fresh();
    }

    public function test_reserved_mad_couriers_do_not_get_other_clients_courses(): void
    {
        $this->company->update(['auto_pickup' => 'on']);
        // Au planning, Koffi ramasse chez la boutique ; il est aussi en MAD chez Ivoire Assurances
        CourierRule::create(['company_id' => $this->company->id, 'type' => 'pickup', 'courier_id' => $this->courierB->id, 'merchant_ids' => [$this->merchant->id]]);
        $plan = $this->plan($this->madClient, ['formula' => 'mad_contract', 'courier_ids' => [$this->courierB->id]]);

        // Réservé (par défaut) : la course de la boutique attend un autre livreur
        $order = $this->confirm($this->createOrder());
        $this->assertSame([OrderStatus::Confirmed, null], [$order->status, $order->pickup_courier_id]);

        // La liste des livreurs le montre en MAD
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/couriers')->assertOk()->assertJsonFragment(['merchant' => 'Ivoire Assurances', 'reserved_now' => true, 'exclusive' => true]);

        // Contrat non réservé : il reçoit de nouveau les courses du planning
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/billing-plans/{$plan->id}", ['options' => ['mad_exclusive' => false]])->assertOk()
            ->assertJsonPath('data.effective_options.mad_exclusive', false);
        $this->assertSame($this->courierB->id, $this->confirm($this->createOrder())->pickup_courier_id);

        // Réservé seulement le mardi : le lundi, il fait les courses du planning ; et pas celles du client MAD
        $this->patchJson("/api/v1/billing-plans/{$plan->id}", ['options' => ['mad_exclusive' => true, 'mad_days' => [2]]])->assertOk();
        $this->assertSame($this->courierB->id, $this->confirm($this->createOrder())->pickup_courier_id);
        $mad = $this->confirm($this->createOrder(['activity' => 'mad'], null, $this->madClient));
        $this->assertNull($mad->pickup_courier_id);

        // Mardi : les courses du client MAD lui reviennent
        $this->travelTo('2026-11-24 10:00:00');
        $mad = $this->confirm($this->createOrder(['activity' => 'mad'], null, $this->madClient));
        $this->assertSame($this->courierB->id, $mad->pickup_courier_id);
    }

    public function test_reservation_hours_follow_the_contract(): void
    {
        $plan = $this->plan($this->madClient, ['formula' => 'mad_contract', 'courier_ids' => [$this->courierB->id], 'options' => ['mad_from' => '08:00', 'mad_to' => '12:00']]);
        $this->assertSame(['08:00', '12:00'], [$plan->options['mad_from'], $plan->options['mad_to']]);

        // En dehors des horaires : circuit habituel ; pendant, au livreur attitré
        $this->travelTo(self::MONDAY.' 15:00:00');
        $this->assertNull($this->confirm($this->createOrder(['activity' => 'mad'], null, $this->madClient))->pickup_courier_id);
        $this->travelTo(self::MONDAY.' 09:00:00');
        $this->assertSame($this->courierB->id, $this->confirm($this->createOrder(['activity' => 'mad'], null, $this->madClient))->pickup_courier_id);

        // Horaires incomplets refusés
        $this->patchJson("/api/v1/billing-plans/{$plan->id}", ['options' => ['mad_from' => '08:00', 'mad_to' => null]])->assertUnprocessable();
    }

    public function test_proof_of_delivery_follows_the_settings(): void
    {
        $plan = $this->plan($this->merchant, ['formula' => 'distribution_contract']);
        $order = $this->createOrder(['activity' => 'distribution']);
        $order->forceFill(['status' => OrderStatus::OutForDelivery])->save();
        Sanctum::actingAs($this->dispatcher);

        $this->getJson("/api/v1/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.billed_monthly', true)
            ->assertJsonPath('data.proof', ['proof_name' => true, 'proof_signature' => false, 'proof_photo' => false]);

        // Par défaut : le nom de la personne qui reçoit
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered'])->assertUnprocessable()->assertJsonValidationErrors('received_by_name');

        // Entreprise : signature demandée en plus ; le contrat demande aussi la photo
        $this->company->update(['activity_options' => ['distribution' => ['proof_signature' => true]]]);
        $plan->update(['options' => ['proof_photo' => true]]);
        $signature = 'data:image/png;base64,'.base64_encode(UploadedFile::fake()->image('s.png', 20, 10)->getContent());
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered', 'received_by_name' => 'Mme Bamba'])->assertUnprocessable()->assertJsonValidationErrors('signature');
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered', 'received_by_name' => 'Mme Bamba', 'signature' => $signature])->assertUnprocessable()->assertJsonValidationErrors('photo');

        $this->post("/api/v1/orders/{$order->id}/attachments", ['type' => 'photo_delivery', 'file' => UploadedFile::fake()->image('d.jpg')], ['Accept' => 'application/json'])->assertCreated();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered', 'received_by_name' => ' Mme Bamba, secrétariat ', 'signature' => $signature])->assertOk()
            ->assertJsonPath('data.received_by_name', 'Mme Bamba, secrétariat');
        $this->assertSame(['photo_delivery', 'signature_delivery'], $order->attachments()->orderBy('id')->pluck('type')->all());

        // Le client apprend qui a reçu, sans montant
        $message = OutboundMessage::where('template_name', 'course_remise')->sole();
        $this->assertStringContainsString('Reçue par : Mme Bamba, secrétariat', $message->body);
        $this->assertStringNotContainsString('encaissé', $message->body);
    }

    public function test_e_commerce_deliveries_are_unchanged(): void
    {
        $this->plan($this->merchant, ['formula' => 'distribution_contract']);
        $order = $this->createOrder();
        $order->forceFill(['status' => OrderStatus::OutForDelivery])->save();
        Sanctum::actingAs($this->dispatcher);

        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered', 'collected_amount' => $order->cod_amount])->assertOk()
            ->assertJsonPath('data.billed_monthly', false);
        $this->assertSame('colis_livre', OutboundMessage::where('recipient_type', 'merchant')->latest('id')->value('template_name'));
    }

    public function test_recipient_messages_follow_the_settings(): void
    {
        $this->company->update(['notify_recipients' => true]);
        $plan = $this->plan($this->merchant, ['formula' => 'distribution_contract']);
        $send = function () {
            $order = $this->createOrder(['activity' => 'distribution']);
            $order->forceFill(['status' => OrderStatus::PickedUp])->save();
            app(OrderDispatcher::class)->assign($this->dispatcher, $order, AssignmentType::Delivery, $this->courierA);
            app(OrderWorkflow::class)->transition($this->dispatcher, $order->fresh(), OrderStatus::OutForDelivery);

            return OutboundMessage::where('recipient_type', 'recipient')->latest('id')->first();
        };

        // Par défaut : « un envoi est en route », sans montant
        $message = $send();
        $this->assertSame('envoi_en_route', $message->template_name);
        $this->assertStringNotContainsString('payer', $message->body);

        // Contrat sans message au destinataire
        $count = OutboundMessage::where('recipient_type', 'recipient')->count();
        $plan->update(['options' => ['notify_recipient' => false]]);
        $send();
        $this->assertSame($count, OutboundMessage::where('recipient_type', 'recipient')->count());
    }

    public function test_company_settings_are_saved_and_cleaned(): void
    {
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['activity_options' => [
            'distribution' => ['proof_photo' => true, 'unknown' => 1],
            'mad' => ['mad_days' => [5, 1, 1], 'mad_exclusive' => false],
        ]])->assertOk()
            ->assertJsonPath('data.activity_options.distribution.proof_photo', true)
            ->assertJsonPath('data.activity_options.distribution.proof_name', true)
            ->assertJsonPath('data.activity_options.mad.mad_days', [1, 5])
            ->assertJsonPath('data.activity_options.mad.mad_exclusive', false);
        $this->assertSame(['proof_photo' => true], $this->company->fresh()->activity_options['distribution']);

        // Réservé à l'administrateur
        Sanctum::actingAs($this->dispatcher);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['activity_options' => ['mad' => ['mad_exclusive' => true]]])->assertForbidden();
    }

    public function test_courses_of_a_lot_planning_and_courier_space(): void
    {
        DistributionRate::create(['company_id' => $this->company->id, 'delivery_delay' => '48h', 'shipment_type' => 'pli', 'price' => 1500]);
        $lot = $this->plan($this->merchant, ['formula' => 'distribution_lot', 'lot_label' => 'Cartes de vœux', 'lot_quantity' => 3, 'lot_price' => 15000]);
        $a = $this->createOrder(['activity' => 'distribution', 'shipment_type' => 'pli', 'billing_plan_id' => $lot->id]);
        $b = $this->createOrder(['activity' => 'distribution', 'shipment_type' => 'pli', 'billing_plan_id' => $lot->id]);
        $other = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);

        // Courses du lot ; liste précise (feuille de route)
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($this->getJson("/api/v1/orders?billing_plan_id={$lot->id}")->assertOk()->json('data'), 'id'));
        $this->assertSame([$other->id], array_column($this->getJson("/api/v1/orders?ids[]={$other->id}")->assertOk()->json('data'), 'id'));

        // Planning : la MAD du jour, livreur attitré absent signalé avec des remplaçants
        $this->plan($this->madClient, ['formula' => 'mad_contract', 'courier_ids' => [$this->courierB->id], 'options' => ['mad_days' => [1, 2, 3, 4, 5]]]);
        $this->postJson('/api/v1/planning/absences', ['courier_id' => $this->courierB->id, 'starts_on' => self::MONDAY, 'ends_on' => self::MONDAY, 'reason' => 'Malade'])->assertCreated();
        $mad = $this->getJson('/api/v1/planning?date='.self::MONDAY)->assertOk()->json('data.mad.0');
        $this->assertSame(['Ivoire Assurances', true, true], [$mad['merchant']['name'], $mad['reserved_today'], $mad['missing']]);
        $this->assertContains($this->courierA->id, array_column($mad['replacements'], 'id'));
        $this->assertFalse($this->getJson('/api/v1/planning?date=2026-11-28')->json('data.mad.0.reserved_today'), 'le samedi : pas de MAD');

        // Application du livreur : la MAD du jour, le lot de ses livraisons
        $b->forceFill(['status' => OrderStatus::PickedUp])->save();
        app(OrderDispatcher::class)->assign($this->dispatcher, $b, AssignmentType::Delivery, $this->courierB);
        Sanctum::actingAs($this->courierB->user);
        $this->getJson('/api/v1/courier/missions')->assertOk()
            ->assertJsonPath('meta.mad.merchant', 'Ivoire Assurances')
            ->assertJsonPath('data.0.order.lot.name', 'Cartes de vœux')
            ->assertJsonPath('data.0.order.billed_monthly', true);
    }

    public function test_pay_rules_by_activity_and_mad_days(): void
    {
        $this->payPlan(['delivery' => ['event' => 'delivery', 'calc' => 'fixed', 'amount' => 500, 'conditions' => ['activities' => ['ecommerce', 'distribution']]]],
            $this->courierB, ['pay_period' => 'monthly', 'mad_daily_rate' => 3000]);
        $this->plan($this->madClient, ['formula' => 'mad_contract', 'courier_ids' => [$this->courierB->id], 'options' => ['mad_days' => [1, 2, 3, 4, 5]]]);
        $pay = app(CourierPay::class);

        // Course e-commerce payée à la course ; course MAD non (payée à la journée)
        $this->assertSame(500, $pay->expectedFor($this->courierB, AssignmentType::Delivery, $this->createOrder()));
        $this->assertNull($pay->expectedFor($this->courierB, AssignmentType::Delivery, $this->createOrder(['activity' => 'mad'], null, $this->madClient)));

        // Novembre 2026 : 21 jours de semaine, moins une absence
        Sanctum::actingAs($this->dispatcher);
        $this->postJson('/api/v1/planning/absences', ['courier_id' => $this->courierB->id, 'starts_on' => self::MONDAY, 'ends_on' => self::MONDAY])->assertCreated();
        $this->travelTo('2026-12-01 00:20:00');
        $this->artisan('payroll:close')->assertSuccessful();
        $line = CourierEarning::withoutGlobalScopes()->where('type', 'mad_days')->sole();
        $this->assertSame(60000, $line->amount);
        $this->assertStringContainsString('20 jour(s)', $line->detail);
        $this->assertStringContainsString('Ivoire Assurances', $line->detail);
        $this->assertNotNull($line->payout_id);

        // Montant par jour sans période de paie : refusé
        Sanctum::actingAs($this->admin);
        $this->patchJson('/api/v1/pay-plans/'.PayPlan::where('courier_id', $this->courierB->id)->value('id'), ['pay_period' => null])->assertUnprocessable()->assertJsonValidationErrors('pay_period');
    }

    public function test_invoice_payments_reach_the_cash_desk(): void
    {
        $this->plan($this->merchant, ['formula' => 'distribution_contract']);
        $this->createOrder(['activity' => 'distribution']);
        $this->travelTo('2026-12-01 08:00:00');
        $this->artisan('billing:invoice')->assertSuccessful();
        $invoice = ClientInvoice::sole();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/issue")->assertOk();
        $this->postJson("/api/v1/client-invoices/{$invoice->id}/payments", ['amount' => 20000, 'method' => 'mobile_money', 'reference' => 'OM-123', 'paid_on' => '2026-12-01'])->assertOk();

        $this->getJson('/api/v1/finance/invoice-payments?month=2026-12')->assertOk()
            ->assertJsonPath('total', 20000)
            ->assertJsonPath('data.0.reference', 'OM-123')
            ->assertJsonPath('data.0.invoice.merchant', $this->merchant->business_name)
            ->assertJsonPath('by_method.Mobile Money', 20000);
        $this->getJson('/api/v1/finance/invoice-payments?month=2026-11')->assertOk()->assertJsonPath('total', 0);
    }
}
