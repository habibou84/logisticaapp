<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\BillingPlan;
use App\Models\CashCollection;
use App\Models\ClientInvoice;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\ActivityOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Point du jour (page Courses) et tableau de bord de l'équipe : prévues, livrées, argent de l'e-commerce, plis et
 * accusés de la distribution, livreurs en MAD, réglages à faire pour l'assignation automatique, « À faire maintenant ».
 */
class DayReportTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->buildWorld();
        $this->cashier = $this->userWithRole(Role::Cashier);
    }

    private function deliver(array $data = [], array $attributes = []): Order
    {
        $order = $this->createOrder($data);
        if ($attributes !== []) {
            $order->forceFill($attributes)->save();
        }
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();

        return $order->fresh();
    }

    public function test_the_day_shows_deliveries_takings_and_money_with_couriers(): void
    {
        $first = $this->deliver(['items_amount' => 10000]);
        $second = $this->deliver(['items_amount' => 20000]);
        $this->createOrder(['items_amount' => 5000]);
        $collected = (int) CashCollection::query()->sum('amount_collected');
        $this->assertSame(30000, $collected);

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/reports/day')->assertOk()->json('data');
        $this->assertSame([3, 2, 1], [$data['counts']['planned'], $data['counts']['delivered'], $data['counts']['in_progress']]);
        $money = $data['money'];
        $this->assertSame($first->delivery_fee + $second->delivery_fee, $money['fees']);
        $this->assertSame([$collected, 0, $collected, 1], [$money['collected'], $money['at_desk'], $money['with_couriers'], $money['couriers_holding']]);
        $this->assertSame($collected - $money['fees'], $money['net_to_merchants']);
        $this->assertTrue($data['share_amounts']);

        // Le livreur verse l'argent : il passe à la caisse
        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierB->id, 'amount_received' => $collected])->assertCreated();
        Sanctum::actingAs($this->admin);
        $money = $this->getJson('/api/v1/reports/day')->json('data.money');
        $this->assertSame([$collected, 0, 0], [$money['at_desk'], $money['with_couriers'], $money['couriers_holding']]);

        // Un autre jour : rien ; l'entreprise retire les montants du point partagé
        $this->getJson('/api/v1/reports/day?day=2020-01-01')->assertOk()->assertJsonPath('data.counts.delivered', 0);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['dashboard_settings' => ['share_amounts' => false]])->assertOk()
            ->assertJsonPath('data.dashboard_settings.share_amounts', false)->assertJsonPath('data.dashboard_settings.lot_due_hours', 48);
        $this->getJson('/api/v1/reports/day')->assertJsonPath('data.share_amounts', false);
    }

    public function test_distribution_counts_letters_receipts_and_lots_and_money_stays_ecommerce(): void
    {
        $proof = fn (bool $photo) => ['distribution' => ['proof_name' => false, 'proof_signature' => false, 'proof_photo' => $photo, 'receipt_label' => false]];
        $this->company->update(['activity_options' => $proof(false)]);
        $this->deliver(['items_amount' => 10000]);
        $this->deliver(['items_amount' => 0], ['activity' => 'distribution', 'shipment_type' => 'pli', 'shipment_quantity' => 12]);
        // Accusé demandé ensuite (comme une livraison faite hors connexion) : il manque
        $this->company->update(['activity_options' => $proof(true)]);
        app(ActivityOptions::class)->forget();
        $lot = BillingPlan::create([
            'company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'formula' => 'distribution_lot', 'activity' => 'distribution',
            'delivery_delay' => '48h', 'starts_on' => today()->subWeek()->toDateString(), 'lot_label' => 'Relevés', 'lot_quantity' => 500,
            'lot_price' => 100000, 'lot_due_on' => today()->addDay()->toDateString(),
        ]);
        BillingPlan::create([
            'company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'formula' => 'distribution_lot', 'activity' => 'distribution',
            'delivery_delay' => '48h', 'starts_on' => today()->subWeek()->toDateString(), 'lot_label' => 'Plus tard', 'lot_quantity' => 50,
            'lot_price' => 10000, 'lot_due_on' => today()->addDays(10)->toDateString(),
        ]);

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/reports/day')->assertOk()->json('data');
        $this->assertSame(1, $data['money']['delivered']);
        $this->assertSame(10000, $data['money']['collected']);
        $d = $data['distribution'];
        $this->assertSame([1, 12, 12, 1], [$d['delivered'], $d['units_planned'], $d['units_delivered'], $d['missing_receipts']]);
        $this->assertSame([$lot->id], array_column($d['lots_due'], 'id'));
        $this->assertSame(12, collect($data['couriers'])->firstWhere('id', $this->courierB->id)['units'] - 1);

        // Une seule activité : pas d'argent pour la distribution
        $only = $this->getJson('/api/v1/reports/day?activity=distribution')->json('data');
        $this->assertSame([1, null], [$only['counts']['delivered'], $only['money']]);
        $this->assertNull($this->getJson('/api/v1/reports/day?activity=ecommerce')->json('data.distribution'));

        // Alerte des lots réglable : 10 jours, le second lot apparaît
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['dashboard_settings' => ['lot_due_hours' => 240]])->assertOk();
        $this->assertCount(2, $this->getJson('/api/v1/reports/todo')->json('data.lots_due'));
    }

    public function test_money_is_hidden_without_the_cash_right_and_merchants_are_refused(): void
    {
        $this->deliver();
        Sanctum::actingAs($this->dispatcher);
        $data = $this->getJson('/api/v1/reports/day')->assertOk()->json('data');
        $this->assertSame(1, $data['counts']['delivered']);
        if (! $this->dispatcher->can('finance.view')) {
            $this->assertNull($data['money']);
            $this->assertFalse($data['share_amounts']);
        }

        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/reports/day')->assertForbidden();
        $this->getJson('/api/v1/reports/todo')->assertForbidden();
    }

    /** Bandeau de la page Courses : ce qui manque pour que les courses soient assignées automatiquement */
    public function test_missing_settings_for_automatic_assignment_are_listed_until_done(): void
    {
        $this->company->forceFill(['auto_pickup' => 'off', 'auto_delivery' => 'trial', 'validation_mode' => 'manual', 'daily_cutoff_time' => null])->save();
        Sanctum::actingAs($this->admin);
        $keys = array_column($this->getJson('/api/v1/reports/auto-assign-checks')->assertOk()->json('data'), 'key');
        $this->assertSame(['planning', 'auto_pickup', 'auto_delivery', 'validation', 'cutoff'], $keys);

        // Contrat MAD sans livreur désigné
        $mad = BillingPlan::create([
            'company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'formula' => 'mad_contract', 'monthly_fee' => 40000,
            'included_quantity' => 10, 'delivery_delay' => '24h', 'starts_on' => today()->subMonth()->toDateString(),
        ]);
        $this->company->forceFill(['auto_pickup' => 'on', 'auto_delivery' => 'on', 'validation_mode' => 'auto', 'daily_cutoff_time' => '16:00'])->save();
        $this->assertSame(['planning', 'mad'], array_column($this->getJson('/api/v1/reports/auto-assign-checks')->json('data'), 'key'));
        $this->assertSame([$mad->id], array_column($this->getJson('/api/v1/reports/todo')->json('data.mad_uncovered'), 'id'));

        $mad->couriers()->attach($this->courierA->id);
        $this->assertSame(['planning'], array_column($this->getJson('/api/v1/reports/auto-assign-checks')->json('data'), 'key'));
        $this->assertSame([], $this->getJson('/api/v1/reports/day')->json('data.mad.uncovered'));

        // Réservé à qui peut changer les réglages
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/reports/auto-assign-checks')->assertForbidden();
    }

    /** « À faire maintenant » : factures à émettre (brouillons et clients du mois dernier pas encore facturés) */
    public function test_invoices_to_issue_and_hidden_cards(): void
    {
        $this->travelTo(now()->startOfMonth()->subMonth()->addDays(10)->setTime(10, 0));
        $plan = BillingPlan::create([
            'company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'formula' => 'distribution_contract', 'monthly_fee' => 40000,
            'included_quantity' => 10, 'overage_price' => 500, 'delivery_delay' => '24h', 'starts_on' => now()->startOfMonth()->toDateString(),
        ]);
        $this->travelBack();

        Sanctum::actingAs($this->admin);
        $todo = $this->getJson('/api/v1/reports/todo')->assertOk()->json('data.invoices_to_issue');
        $this->assertSame([0, 1, now()->subMonthNoOverflow()->format('Y-m')], [$todo['drafts'], $todo['missing'], $todo['period']]);

        // Calculée : un brouillon à émettre, plus de client manquant
        $this->postJson('/api/v1/client-invoices/generate', ['month' => now()->subMonthNoOverflow()->format('Y-m')])->assertOk();
        $this->assertSame(1, ClientInvoice::query()->where('merchant_id', $this->merchant->id)->count());
        $this->assertNotNull($plan->id);
        $todo = $this->getJson('/api/v1/reports/todo')->json('data.invoices_to_issue');
        $this->assertSame([1, 0], [$todo['drafts'], $todo['missing']]);

        // Cartes masquées par l'entreprise
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['dashboard_settings' => ['hidden_cards' => ['invoices_to_issue', 'inconnue']]])
            ->assertJsonValidationErrors('dashboard_settings.hidden_cards.1');
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['dashboard_settings' => ['hidden_cards' => ['invoices_to_issue']]])->assertOk();
        $this->getJson('/api/v1/reports/todo')->assertJsonPath('data.hidden_cards', ['invoices_to_issue']);
    }

    /** Tableau de bord : période, nouvelles courses et livreurs du jour */
    public function test_a_period_counts_new_courses_and_lists_the_couriers(): void
    {
        $this->deliver(['items_amount' => 10000]);
        $this->deliver(['items_amount' => 20000]);
        $this->createOrder(['items_amount' => 5000, 'delivery_scheduled_date' => today()->addDays(3)->toDateString()]);

        Sanctum::actingAs($this->admin);
        $from = today()->subDays(6)->toDateString();
        $data = $this->getJson('/api/v1/reports/day?from='.$from.'&to='.today()->toDateString())->assertOk()->json('data');
        $this->assertSame([$from, today()->toDateString()], [$data['from'], $data['to']]);
        $this->assertSame([3, 1, 2], [$data['counts']['created'], $data['counts']['created_later'], $data['counts']['delivered']]);

        $courier = collect($data['couriers'])->firstWhere('id', $this->courierB->id);
        $this->assertSame(2, $courier['delivered']);
        $this->assertSame($data['money']['collected'], $courier['collected']);

        Sanctum::actingAs($this->dispatcher);
        if (! $this->dispatcher->can('finance.view')) {
            $this->assertNull(collect($this->getJson('/api/v1/reports/day')->json('data.couriers'))->firstWhere('id', $this->courierB->id)['collected']);
        }
        $this->getJson('/api/v1/reports/day?from=2020-01-01&to=2020-12-31')->assertStatus(422);
    }
}
