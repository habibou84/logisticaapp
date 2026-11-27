<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Remise à zéro de l'activité (php artisan app:reset-activity) : courses, argent, messages, stock et statistiques
 * effacés ; entreprise, utilisateurs, clients, livreurs, zones et tarifs gardés.
 */
class ResetActivityTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
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

        return $order->fresh();
    }

    /** Une journée d'essai : courses livrées, encaissées, versées à la caisse, avance, photo, course annulée */
    private function trialDay(): Order
    {
        $order = $this->deliver(10000);
        $this->deliver(5000);
        $this->createOrder()->delete();
        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/v1/finance/remittances', ['courier_id' => $this->courierB->id, 'amount_received' => 15000])->assertCreated();
        $this->postJson("/api/v1/finance/couriers/{$this->courierA->id}/advances", ['amount' => 2000, 'reason' => 'Carburant'])->assertCreated();

        Storage::disk('local')->put("orders/{$order->id}/preuve.jpg", 'photo');
        DB::table('order_attachments')->insert([
            'order_id' => $order->id, 'type' => 'proof', 'disk' => 'local', 'path' => "orders/{$order->id}/preuve.jpg",
            'created_at' => now(),
        ]);

        return $order;
    }

    public function test_the_simulation_counts_without_erasing(): void
    {
        $this->trialDay();

        $this->artisan('app:reset-activity')->expectsOutputToContain('Simulation')->assertSuccessful();

        $this->assertSame(3, Order::withTrashed()->count());
        $this->assertGreaterThan(0, DB::table('courier_earnings')->count());
    }

    public function test_a_wrong_confirmation_erases_nothing(): void
    {
        $this->trialDay();

        $this->artisan('app:reset-activity --execute')->expectsQuestion('Tapez EFFACER pour confirmer', 'oui')->assertFailed();

        $this->assertSame(3, Order::withTrashed()->count());
    }

    public function test_the_activity_is_erased_and_the_setup_kept(): void
    {
        $order = $this->trialDay();
        $kept = [
            'users' => User::count(), 'merchants' => Merchant::count(), 'couriers' => Courier::count(),
            'zones' => Zone::count(), 'pricing_rules' => PricingRule::count(), 'pay_plans' => DB::table('pay_plans')->count(),
        ];
        $this->assertGreaterThan(0, DB::table('cash_collections')->count());
        $this->assertGreaterThan(0, DB::table('courier_remittances')->count());
        $this->assertGreaterThan(0, DB::table('courier_advances')->count());
        $this->assertGreaterThan(0, DB::table('order_events')->count());

        $this->artisan('app:reset-activity --execute')->expectsQuestion('Tapez EFFACER pour confirmer', 'EFFACER')
            ->expectsOutputToContain('1 fichier(s) supprimé(s)')->assertSuccessful();

        $this->assertSame(0, Order::withTrashed()->count());
        foreach (['order_events', 'order_assignments', 'order_attachments', 'cash_collections', 'courier_earnings',
            'courier_advances', 'courier_remittances', 'merchant_ledger_entries', 'cash_logs', 'outbound_messages', 'recipients'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "$table n'est pas vide");
        }
        Storage::disk('local')->assertMissing("orders/{$order->id}/preuve.jpg");

        $this->assertSame($kept, [
            'users' => User::count(), 'merchants' => Merchant::count(), 'couriers' => Courier::count(),
            'zones' => Zone::count(), 'pricing_rules' => PricingRule::count(), 'pay_plans' => DB::table('pay_plans')->count(),
        ]);

        // Les soldes repartent de zéro : le livreur ne doit plus rien à la caisse
        Sanctum::actingAs($this->admin);
        $this->createOrder();
        $this->assertSame(1, Order::count());
    }
}
