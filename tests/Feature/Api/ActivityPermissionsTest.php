<?php

namespace Tests\Feature\Api;

use App\Enums\Activity;
use App\Enums\ClientInvoiceStatus;
use App\Enums\Role;
use App\Models\BillingPlan;
use App\Models\ClientInvoice;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\User;
use App\Notifications\OrderAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Activités (e-commerce, distribution, MAD) : clients à plusieurs activités, périmètre de l'équipe,
 * réglage fin des permissions, factures et chiffre d'affaires séparés.
 */
class ActivityPermissionsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private Merchant $madClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo('2026-11-10 10:00:00');
        foreach ([$this->cocody, $this->yopougon] as $zone) {
            $zone->update(['in_forfait' => true]);
        }

        // Boutique Test : e-commerce et distribution ; un second client en MAD seulement
        $this->merchant->syncActivities([Activity::Ecommerce, Activity::Distribution]);
        $this->plan($this->merchant, 'distribution_contract');
        $this->madClient = Merchant::factory()->create(['company_id' => $this->company->id, 'business_name' => 'Banque MAD', 'pickup_zone_id' => $this->cocody->id]);
        $this->madClient->syncActivities([Activity::Mad]);
        $this->plan($this->madClient, 'mad_contract');
    }

    private function plan(Merchant $merchant, string $formula): BillingPlan
    {
        return BillingPlan::create([
            'company_id' => $this->company->id, 'merchant_id' => $merchant->id, 'formula' => $formula, 'monthly_fee' => 40000,
            'included_quantity' => 10, 'overage_price' => 500, 'delivery_delay' => '24h', 'starts_on' => '2026-11-01',
        ]);
    }

    private function staff(Role $role, array $activities = [], array $attributes = []): User
    {
        return $this->userWithRole($role, ['activities' => $activities, ...$attributes]);
    }

    public function test_a_client_with_several_activities_chooses_the_activity_of_each_course(): void
    {
        $ecommerce = $this->createOrder(['activity' => 'ecommerce']);
        $distribution = $this->createOrder(['activity' => 'distribution', 'shipment_type' => 'pli']);
        // Sans activité précisée (API, import) : l'e-commerce
        $default = $this->createOrder();

        $this->assertSame([Activity::Ecommerce, 1500, null], [$ecommerce->activity, $ecommerce->delivery_fee, $ecommerce->billing_basis]);
        $this->assertSame([Activity::Distribution, 0, 'forfait'], [$distribution->activity, $distribution->delivery_fee, $distribution->billing_basis]);
        $this->assertSame(Activity::Ecommerce, $default->activity);

        // Activité que le client n'a pas
        Sanctum::actingAs($this->merchantUser);
        $this->postJson('/api/v1/orders', ['recipient_phone' => '0707070707', 'delivery_zone_id' => $this->yopougon->id, 'fee_payer' => 'merchant', 'activity' => 'mad'])
            ->assertUnprocessable()->assertJsonFragment(['message' => 'Ce client n\'a pas l\'activité MAD : ajoutez-la sur sa fiche.']);

        // Une activité qui a des courses ne se retire pas
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/merchants/{$this->merchant->id}", ['activities' => ['ecommerce']])->assertUnprocessable();
        $this->getJson("/api/v1/merchants/{$this->merchant->id}")->assertOk()
            ->assertJsonPath('data.activities', ['ecommerce', 'distribution'])
            ->assertJsonPath('data.billing_plans.0.activity', 'distribution');
    }

    public function test_the_team_only_sees_its_activities(): void
    {
        $ecommerce = $this->createOrder(['activity' => 'ecommerce']);
        $distribution = $this->createOrder(['activity' => 'distribution']);
        $mad = $this->createOrder([], $this->admin, $this->madClient);

        // L'administrateur voit tout et filtre par activité
        Sanctum::actingAs($this->admin);
        $this->assertCount(3, $this->getJson('/api/v1/orders')->assertOk()->json('data'));
        $this->assertSame([$mad->id], array_column($this->getJson('/api/v1/orders?activity=mad')->json('data'), 'id'));

        // Agent MAD : courses et clients MAD seulement
        $agent = $this->staff(Role::Dispatcher, ['mad']);
        Sanctum::actingAs($agent);
        $this->assertSame([$mad->id], array_column($this->getJson('/api/v1/orders')->assertOk()->json('data'), 'id'));
        $this->getJson("/api/v1/orders/{$ecommerce->id}")->assertNotFound();
        $this->assertSame(['Banque MAD'], array_column($this->getJson('/api/v1/merchants')->assertOk()->json('data'), 'business_name'));
        $this->postJson('/api/v1/orders', ['merchant_id' => $this->merchant->id, 'recipient_phone' => '0707070707', 'delivery_zone_id' => $this->yopougon->id, 'fee_payer' => 'merchant'])
            ->assertStatus(404);

        // Agent distribution : un client à plusieurs activités, seulement ses courses de distribution
        $dist = $this->staff(Role::Dispatcher, ['distribution']);
        Sanctum::actingAs($dist);
        $this->assertSame([$distribution->id], array_column($this->getJson('/api/v1/orders')->json('data'), 'id'));
        $created = $this->postJson('/api/v1/orders', [
            'merchant_id' => $this->merchant->id, 'recipient_phone' => '0707070707', 'delivery_zone_id' => $this->yopougon->id, 'fee_payer' => 'merchant',
        ])->assertCreated();
        $this->assertSame('distribution', $created->json('data.activity'));

        // Alertes : seulement celles de son périmètre
        $this->assertSame([], (new OrderAlert($ecommerce, 'incident', 'T', 'B'))->via($agent));
        $this->assertNotSame([], (new OrderAlert($mad, 'incident', 'T', 'B'))->via($agent));
    }

    public function test_ecommerce_payouts_are_reserved_to_the_ecommerce_team(): void
    {
        Sanctum::actingAs($this->staff(Role::Cashier, ['distribution']));
        $this->getJson('/api/v1/finance/payouts')->assertForbidden();
        $this->getJson('/api/v1/finance/cash')->assertOk();

        Sanctum::actingAs($this->staff(Role::Cashier, ['ecommerce']));
        $this->getJson('/api/v1/finance/payouts')->assertOk();
    }

    public function test_invoices_have_one_series_per_activity_and_follow_the_perimeter(): void
    {
        $this->createOrder(['activity' => 'distribution']);
        $this->createOrder([], $this->admin, $this->madClient);
        $this->travelTo('2026-12-01 08:00:00');
        $this->artisan('billing:invoice')->assertSuccessful();

        $this->assertSame(['distribution', 'mad'], ClientInvoice::orderBy('activity')->pluck('activity')->map->value->all());

        Sanctum::actingAs($this->admin);
        foreach (ClientInvoice::orderBy('activity')->get() as $invoice) {
            $this->postJson("/api/v1/client-invoices/{$invoice->id}/issue")->assertOk();
        }
        $this->assertSame(['DIS-202611-0001', 'MAD-202611-0001'], ClientInvoice::orderBy('activity')->pluck('number')->all());

        // Responsable MAD : factures, formules et calcul de son activité seulement
        $manager = $this->staff(Role::ActivityManager, ['mad']);
        Sanctum::actingAs($manager);
        $this->assertSame(['mad'], array_column($this->getJson('/api/v1/client-invoices')->assertOk()->json('data'), 'activity'));
        $distributionInvoice = ClientInvoice::withoutGlobalScopes()->where('activity', 'distribution')->sole();
        $this->getJson("/api/v1/client-invoices/{$distributionInvoice->id}")->assertNotFound();
        $this->postJson('/api/v1/client-invoices/generate', ['month' => '2026-11', 'merchant_id' => $this->merchant->id, 'activity' => 'distribution'])->assertForbidden();
        $this->postJson("/api/v1/merchants/{$this->madClient->id}/billing-plans", [
            'formula' => 'distribution_unit', 'delivery_delay' => '24h', 'starts_on' => '2026-12-01',
        ])->assertForbidden();
        $this->postJson("/api/v1/merchants/{$this->madClient->id}/billing-plans", [
            'formula' => 'mad_contract', 'monthly_fee' => 50000, 'included_quantity' => 20, 'delivery_delay' => '24h', 'starts_on' => '2026-12-01',
        ])->assertCreated();
    }

    public function test_revenue_is_split_by_activity(): void
    {
        $sale = $this->createOrder(['activity' => 'ecommerce']);
        MerchantLedgerEntry::create(['company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'type' => 'delivery_fee', 'amount' => -1500, 'order_id' => $sale->id]);
        $this->createOrder([], $this->admin, $this->madClient);
        $this->travelTo('2026-12-01 08:00:00');
        $this->artisan('billing:invoice', ['--month' => '2026-11'])->assertSuccessful();
        ClientInvoice::where('activity', 'mad')->sole()->update(['status' => ClientInvoiceStatus::Issued, 'number' => 'MAD-202611-0001']);

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/revenue?from=2026-11&to=2026-11')->assertOk()->json('data');
        $november = $data['months'][0];
        $this->assertSame(1500, $november['activities']['ecommerce']['billed']);
        $this->assertSame(0, $november['activities']['distribution']['billed']);
        $this->assertSame(40000, $november['activities']['mad']['billed']);
        $this->assertSame(41500, $data['totals']['total']['billed']);
        $this->assertSame(1, $november['activities']['mad']['orders']);

        // Responsable MAD : son activité seulement ; dispatcher : pas de chiffre d'affaires
        Sanctum::actingAs($this->staff(Role::ActivityManager, ['mad']));
        $this->assertSame(['mad'], array_column($this->getJson('/api/v1/revenue')->assertOk()->json('data.activities'), 'value'));
        Sanctum::actingAs($this->staff(Role::Dispatcher));
        $this->getJson('/api/v1/revenue')->assertForbidden();
    }

    public function test_permissions_can_be_added_or_removed_one_by_one(): void
    {
        // Dispatcher qui voit aussi les factures ; caissier sans droit d'encaisser
        Sanctum::actingAs($this->admin);
        $dispatcherId = $this->postJson('/api/v1/users', [
            'name' => 'Agent', 'phone' => '0102030405', 'password' => 'motdepasse', 'role' => 'dispatcher',
            'activities' => ['distribution'], 'extra_permissions' => ['invoices.view'],
        ])->assertCreated()->assertJsonPath('data.activities', ['distribution'])->assertJsonPath('data.extra_permissions', ['invoices.view'])->json('data.id');
        $cashier = $this->staff(Role::Cashier);
        $this->patchJson("/api/v1/users/{$cashier->id}", ['denied_permissions' => ['invoices.manage']])->assertOk();

        Sanctum::actingAs(User::find($dispatcherId));
        $this->getJson('/api/v1/client-invoices')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertJsonFragment(['invoices.view']);

        Sanctum::actingAs($cashier->fresh());
        $this->assertNotContains('invoices.manage', $this->getJson('/api/v1/auth/me')->json('data.permissions'));
        $this->postJson('/api/v1/client-invoices/generate', ['month' => '2026-11'])->assertForbidden();
        $this->getJson('/api/v1/client-invoices')->assertOk();

        // Seul l'administrateur règle le périmètre et les permissions, jamais les siennes
        Sanctum::actingAs($this->staff(Role::ActivityManager, ['mad']));
        $this->patchJson("/api/v1/users/{$cashier->id}", ['activities' => ['mad']])->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/users/{$this->admin->id}", ['denied_permissions' => ['users.manage']])->assertForbidden();

        // Catalogue de l'écran Utilisateurs
        $roles = $this->getJson('/api/v1/roles')->assertOk();
        $this->assertContains('activity_manager', array_column($roles->json('data'), 'value'));
        $this->assertContains('revenue.view', array_column($roles->json('permissions'), 'value'));
        $this->assertCount(3, $roles->json('activities'));
    }
}
