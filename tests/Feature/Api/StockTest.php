<?php

namespace Tests\Feature\Api;

use App\Enums\LedgerEntryType;
use App\Enums\Role;
use App\Models\Hub;
use App\Models\IncidentReason;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StorageCharge;
use App\Models\StorageContract;
use App\Models\User;
use App\Notifications\StockAlert;
use App\Services\Stock\StockKeeper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Stock : produits, emplacements (chez le marchand ou à l'entrepôt), réservations
 * liées aux courses, préparation à l'entrepôt, inventaires et facturation du stockage.
 */
class StockTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private Hub $hub;

    private User $hubAgent;

    private Product $dress;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->hubAgent = $this->userWithRole(Role::HubAgent);

        $this->hub = Hub::create([
            'company_id' => $this->company->id,
            'name' => 'Entrepôt Cocody',
            'zone_id' => $this->cocody->id,
            'address' => 'Angré 8e tranche',
            'phone' => '0102030405',
        ]);

        $this->dress = Product::create([
            'company_id' => $this->company->id,
            'merchant_id' => $this->merchant->id,
            'sku' => 'ROBE-01',
            'name' => 'Robe wax',
            'price' => 12000,
            'low_stock_threshold' => 3,
        ]);
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    private function receiveAtHub(int $quantity, ?Product $product = null): void
    {
        $this->as($this->hubAgent)->postJson('/api/v1/stock/movements', [
            'product_id' => ($product ?? $this->dress)->id,
            'hub_id' => $this->hub->id,
            'action' => 'receipt',
            'quantity' => $quantity,
        ])->assertCreated();
    }

    private function level(?int $hubId): ?StockLevel
    {
        return StockLevel::where('product_id', $this->dress->id)
            ->whereHas('location', fn ($q) => $hubId ? $q->where('hub_id', $hubId) : $q->whereNull('hub_id'))
            ->first();
    }

    private function warehouseOrder(int $quantity = 2): Order
    {
        $response = $this->as($this->merchantUser)->postJson('/api/v1/orders', [
            'recipient_phone' => '0707070707',
            'delivery_zone_id' => $this->yopougon->id,
            'pickup_hub_id' => $this->hub->id,
            'items' => [['product_id' => $this->dress->id, 'quantity' => $quantity]],
            'fee_payer' => 'recipient',
        ])->assertCreated();

        return Order::findOrFail($response->json('data.id'));
    }

    public function test_hubs_are_managed_by_admin_and_listed_for_merchants(): void
    {
        $this->as($this->admin)->postJson('/api/v1/hubs', ['name' => 'Entrepôt Yopougon', 'zone_id' => $this->yopougon->id])
            ->assertCreated()
            ->assertJsonPath('data.zone_name', 'Yopougon');

        $closed = Hub::create(['company_id' => $this->company->id, 'name' => 'Ancien', 'zone_id' => $this->plateau->id, 'is_active' => false]);

        $this->as($this->merchantUser)->getJson('/api/v1/hubs')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['name' => 'Ancien']);

        $this->postJson('/api/v1/hubs', ['name' => 'X', 'zone_id' => $this->cocody->id])->assertForbidden();
        $this->as($this->admin)->patchJson("/api/v1/hubs/{$closed->id}", ['is_active' => true])->assertOk();
    }

    public function test_merchant_manages_products_and_stock_kept_at_home(): void
    {
        $this->as($this->merchantUser)->postJson('/api/v1/products', [
            'name' => 'Sac cuir', 'sku' => 'SAC-1', 'price' => 25000, 'merchant_id' => $this->merchant->id,
        ])->assertJsonValidationErrors('merchant_id');

        $bag = $this->postJson('/api/v1/products', ['name' => 'Sac cuir', 'sku' => 'SAC-1', 'price' => 25000])
            ->assertCreated()
            ->assertJsonPath('data.merchant_id', $this->merchant->id)
            ->json('data.id');

        $this->postJson('/api/v1/products', ['name' => 'Doublon', 'sku' => 'SAC-1'])->assertJsonValidationErrors('sku');

        $this->postJson('/api/v1/stock/movements', ['product_id' => $bag, 'action' => 'receipt', 'quantity' => 10])
            ->assertCreated()
            ->assertJsonPath('product.available', 10)
            ->assertJsonPath('product.levels.0.label', 'Chez le marchand');

        // Le stock de l'entrepôt est tenu par l'entreprise
        $this->postJson('/api/v1/stock/movements', ['product_id' => $bag, 'hub_id' => $this->hub->id, 'action' => 'receipt', 'quantity' => 5])
            ->assertForbidden();

        $this->postJson('/api/v1/stock/movements', ['product_id' => $bag, 'action' => 'withdrawal', 'quantity' => 3])->assertCreated();
        $this->postJson('/api/v1/stock/movements', ['product_id' => $bag, 'action' => 'count', 'quantity' => 6, 'note' => 'Comptage'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'adjustment')
            ->assertJsonPath('data.on_hand_change', -1)
            ->assertJsonPath('product.on_hand', 6);

        // Un employé du marchand voit les produits mais ne les gère pas
        $staff = $this->userWithRole(Role::MerchantStaff, ['merchant_id' => $this->merchant->id]);
        $this->as($staff)->getJson('/api/v1/products')->assertOk()->assertJsonCount(2, 'data');
        $this->postJson('/api/v1/products', ['name' => 'X'])->assertForbidden();

        // Un autre marchand ne voit rien
        $other = $this->userWithRole(Role::MerchantOwner, ['merchant_id' => Merchant::factory()->create(['company_id' => $this->company->id])->id]);
        $this->as($other)->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/products/{$bag}")->assertForbidden();
    }

    public function test_warehouse_stock_is_kept_by_the_company(): void
    {
        $this->receiveAtHub(10);
        $this->assertSame(10, $this->level($this->hub->id)->on_hand);

        // L'agent de dépôt ne touche pas au stock gardé chez le marchand
        $this->postJson('/api/v1/stock/movements', ['product_id' => $this->dress->id, 'action' => 'receipt', 'quantity' => 1])->assertForbidden();

        $this->postJson('/api/v1/stock/movements', [
            'product_id' => $this->dress->id, 'hub_id' => $this->hub->id, 'action' => 'withdrawal', 'quantity' => 11,
        ])->assertJsonValidationErrors('quantity');

        $this->as($this->merchantUser)->getJson("/api/v1/products/{$this->dress->id}")
            ->assertOk()
            ->assertJsonPath('data.levels.0.label', 'Entrepôt Cocody')
            ->assertJsonPath('movements.0.type_label', 'Entrée')
            ->assertJsonPath('movements.0.user_name', $this->hubAgent->name);
    }

    public function test_warehouse_order_reserves_is_prepared_then_delivered(): void
    {
        $this->receiveAtHub(5);

        $order = $this->warehouseOrder(2);
        $this->assertTrue($order->fromWarehouse());
        $this->assertSame($this->cocody->id, $order->pickup_zone_id);
        $this->assertSame('Entrepôt Cocody', $order->pickup_contact_name);
        $this->assertSame(1500, $order->delivery_fee);
        $this->assertSame(24000, $order->items_amount);
        $this->assertSame(25500, $order->cod_amount);
        $this->assertSame('2 × Robe wax', $order->description);
        $this->assertSame(2, $this->level($this->hub->id)->reserved);

        $this->getJson("/api/v1/orders/{$order->id}")
            ->assertJsonPath('data.from_warehouse', true)
            ->assertJsonPath('data.pickup.hub_name', 'Entrepôt Cocody')
            ->assertJsonPath('data.items.0.stock_state', 'reserved');

        // Pas plus que le disponible
        $this->postJson('/api/v1/orders', [
            'recipient_phone' => '0707070707', 'delivery_zone_id' => $this->yopougon->id,
            'pickup_hub_id' => $this->hub->id, 'items' => [['product_id' => $this->dress->id, 'quantity' => 4]], 'fee_payer' => 'recipient',
        ])->assertJsonValidationErrors('items');

        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk()
            ->assertJsonPath('data.status_label', 'À préparer');
        $this->getJson('/api/v1/orders?queue=to_prepare')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/orders?queue=to_pickup')->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])
            ->assertJsonValidationErrors('type');
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])
            ->assertJsonValidationErrors('type');

        $this->as($this->hubAgent)->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'at_hub'])
            ->assertOk()
            ->assertJsonPath('data.status_label', 'Préparé au dépôt');
        $this->assertNotNull($order->fresh()->prepared_at);

        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();

        Notification::fake();
        $this->as($this->courierB->user)->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();

        $level = $this->level($this->hub->id);
        $this->assertSame(3, $level->on_hand);
        $this->assertSame(0, $level->reserved);
        $this->assertSame('shipped', $order->items()->first()->stock_state);

        // Disponible resté à 3 (déjà réservé) : pas de nouveau franchissement du seuil, pas d'alerte
        Notification::assertNotSentTo($this->merchantUser, StockAlert::class);
    }

    public function test_cancelled_and_restocked_orders_release_their_items(): void
    {
        $this->receiveAtHub(5);

        $cancelled = $this->warehouseOrder(2);
        $this->as($this->merchantUser)->postJson("/api/v1/orders/{$cancelled->id}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(0, $this->level($this->hub->id)->reserved);
        $this->assertSame('released', $cancelled->items()->first()->stock_state);

        // Échec de livraison puis remise en stock directe à l'entrepôt
        $order = $this->warehouseOrder(1);
        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'confirmed']);
        $this->as($this->hubAgent)->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'at_hub']);
        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        $this->as($this->courierB->user)->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery']);
        $reason = IncidentReason::where('applies_to', '!=', 'pickup')->where('requires_date', false)->first();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivery_failed', 'incident_reason_id' => $reason->id])->assertOk();

        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'return', 'courier_id' => $this->courierA->id])
            ->assertJsonValidationErrors('type');

        $this->as($this->hubAgent)->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'returned'])
            ->assertOk()
            ->assertJsonPath('data.status_label', 'Remis en stock');

        $level = $this->level($this->hub->id);
        $this->assertSame(5, $level->on_hand);
        $this->assertSame(0, $level->reserved);

        // Une course classique ne se « retourne » pas sans livreur de retour
        $classic = $this->createOrder();
        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$classic->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->as($this->courierA->user)->postJson("/api/v1/orders/{$classic->id}/status", ['status' => 'picked_up'])->assertOk();
        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$classic->id}/status", ['status' => 'at_hub'])->assertOk();
        $this->postJson("/api/v1/orders/{$classic->id}/status", ['status' => 'returned'])->assertJsonValidationErrors('status');
    }

    public function test_order_from_merchant_stock_reserves_at_home_and_alerts_when_low(): void
    {
        $keeper = app(StockKeeper::class);
        $keeper->receive($this->merchantUser, $this->dress, $keeper->location($this->merchant), 5);
        Notification::fake();

        $order = $this->createOrder([
            'items' => [
                ['product_id' => $this->dress->id, 'quantity' => 2, 'unit_price' => 10000],
                ['label' => 'Ceinture offerte', 'quantity' => 1],
            ],
            'items_amount' => null,
        ]);
        $this->assertFalse($order->fromWarehouse());
        $this->assertSame($this->cocody->id, $order->pickup_zone_id);
        $this->assertSame('Riviera 2', $order->pickup_address);
        $this->assertSame(20000, $order->items_amount);
        $this->assertSame(2, $this->level(null)->reserved);
        $this->assertNull($order->items()->where('label', 'Ceinture offerte')->first()->stock_state);

        // Disponible 5 → 3 : seuil atteint, alerte au marchand (une seule fois)
        Notification::assertSentToTimes($this->merchantUser, StockAlert::class, 1);
        Notification::assertSentTo($this->merchantUser, StockAlert::class, fn (StockAlert $n) => str_contains($n->title, 'Stock bas'));
        Notification::assertNotSentTo($this->hubAgent, StockAlert::class);
        $this->createOrder(['items' => [['product_id' => $this->dress->id, 'quantity' => 1]]]);
        Notification::assertSentToTimes($this->merchantUser, StockAlert::class, 1);

        $this->as($this->merchantUser)->getJson('/api/v1/products?low=1')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.available', 2)
            ->assertJsonPath('data.0.is_low', true);

        // Stock déjà bas qui tombe à zéro : nouvelle alerte « Rupture », qui ouvre le stock
        $this->createOrder(['items' => [['product_id' => $this->dress->id, 'quantity' => 2]]]);
        Notification::assertSentToTimes($this->merchantUser, StockAlert::class, 2);
        Notification::assertSentTo($this->merchantUser, StockAlert::class, fn (StockAlert $n) => str_contains($n->title, 'Rupture')
            && $n->toArray($this->merchantUser)['href'] === '/marchand/stock');

        // Produit d'un autre marchand : refusé
        $foreign = Product::create(['company_id' => $this->company->id, 'merchant_id' => Merchant::factory()->create(['company_id' => $this->company->id])->id, 'name' => 'Autre']);
        $this->postJson('/api/v1/orders', [
            'recipient_phone' => '0707070707', 'delivery_zone_id' => $this->yopougon->id,
            'items' => [['product_id' => $foreign->id, 'quantity' => 1]], 'fee_payer' => 'merchant',
        ])->assertJsonValidationErrors('items.0.product_id');
    }

    public function test_storage_is_billed_monthly_once_per_contract(): void
    {
        Carbon::setTestNow('2026-09-01 08:00:00');
        $this->receiveAtHub(10);
        Carbon::setTestNow('2026-09-11 08:00:00');
        $this->as($this->hubAgent)->postJson('/api/v1/stock/movements', [
            'product_id' => $this->dress->id, 'hub_id' => $this->hub->id, 'action' => 'withdrawal', 'quantity' => 4,
        ])->assertCreated();

        Carbon::setTestNow('2026-09-15 08:00:00');
        $order = $this->warehouseOrder(1);
        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'confirmed']);
        $this->as($this->hubAgent)->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'at_hub'])->assertOk();

        $this->as($this->admin)->postJson('/api/v1/storage-contracts', [
            'merchant_id' => $this->merchant->id, 'billing_type' => 'per_unit_day', 'price' => 10, 'starts_on' => '2026-09-01',
        ])->assertCreated()->assertJsonPath('data.description', '10 F par article et par jour');
        $this->postJson('/api/v1/storage-contracts', [
            'merchant_id' => $this->merchant->id, 'billing_type' => 'monthly_flat', 'price' => 5000, 'starts_on' => '2026-08-01',
        ])->assertCreated();
        $this->postJson('/api/v1/storage-contracts', [
            'merchant_id' => $this->merchant->id, 'hub_id' => $this->hub->id, 'billing_type' => 'per_order', 'price' => 300, 'starts_on' => '2026-09-01',
        ])->assertCreated();
        $this->postJson('/api/v1/storage-contracts', [
            'merchant_id' => $this->merchant->id, 'billing_type' => 'free', 'starts_on' => '2026-10-01',
        ])->assertCreated();

        $this->as($this->merchantUser)->postJson('/api/v1/storage-contracts', [
            'merchant_id' => $this->merchant->id, 'billing_type' => 'free', 'starts_on' => '2026-10-01',
        ])->assertForbidden();
        $this->getJson('/api/v1/storage-contracts')->assertOk()->assertJsonCount(4, 'data');

        Carbon::setTestNow('2026-10-01 01:10:00');
        $this->artisan('storage:bill')->assertSuccessful();

        // 10 articles du 1er au 10, 6 du 11 au 30 : 100 + 120 = 220 articles-jours
        $charges = StorageCharge::with('contract')->get()->keyBy(fn ($c) => $c->contract->billing_type->value);
        $this->assertCount(3, $charges);
        $this->assertSame(220, $charges['per_unit_day']->quantity);
        $this->assertSame(2200, $charges['per_unit_day']->amount);
        $this->assertSame(5000, $charges['monthly_flat']->amount);
        $this->assertSame(1, $charges['per_order']->quantity);
        $this->assertSame(300, $charges['per_order']->amount);

        $fees = MerchantLedgerEntry::where('type', LedgerEntryType::StorageFee)->get();
        $this->assertSame(-7500, (int) $fees->sum('amount'));
        $this->assertStringContainsString('septembre 2026', $fees->first()->description);

        // Relancer ne facture pas deux fois
        $this->artisan('storage:bill', ['--month' => '2026-09'])->assertSuccessful();
        $this->assertSame(3, StorageCharge::count());

        // Contrat facturé : le tarif ne change plus, la date de fin si
        $contract = StorageContract::where('billing_type', 'per_unit_day')->first();
        $this->as($this->admin)->putJson("/api/v1/storage-contracts/{$contract->id}", ['price' => 20])->assertJsonValidationErrors('status');
        $this->putJson("/api/v1/storage-contracts/{$contract->id}", ['ends_on' => '2026-10-31'])->assertOk();
        $this->deleteJson("/api/v1/storage-contracts/{$contract->id}")->assertJsonValidationErrors('status');

        // Le reversement déduit les frais de stockage
        $this->userWithRole(Role::Cashier);
        $payout = $this->postJson('/api/v1/finance/payouts', ['merchant_id' => $this->merchant->id])->assertCreated();
        $payout->assertJsonPath('data.total_storage_fees', 7500);

        Carbon::setTestNow();
    }
}
