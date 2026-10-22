<?php

namespace Tests\Feature\Api;

use App\Models\Hub;
use App\Models\Order;
use App\Models\Product;
use App\Notifications\ShopOrderReceived;
use App\Services\Stock\StockKeeper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

class MerchantShopTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private Product $dress;

    private Product $bag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();

        // Robe : stock non suivi ; sac : 3 en stock chez le marchand
        $this->dress = $this->product('Robe wax', 15000);
        $this->bag = $this->product('Sac à main', 8000);
        $keeper = app(StockKeeper::class);
        $keeper->receive($this->merchantUser, $this->bag, $keeper->location($this->merchant), 3);
        $this->merchant->update(['shop_enabled' => true, 'shop_slug' => 'boutique-test']);
    }

    private function product(string $name, int $price, array $attributes = []): Product
    {
        return Product::create(['company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'name' => $name, 'price' => $price, ...$attributes]);
    }

    private function orderData(array $overrides = []): array
    {
        return [
            'name' => 'Aïcha Traoré',
            'phone' => '05 06 07 08 09',
            'zone_id' => $this->yopougon->id,
            'address' => 'Siporex, derrière la pharmacie',
            'note' => 'Appelez avant de passer',
            'items' => [['product_id' => $this->dress->id, 'quantity' => 2]],
            ...$overrides,
        ];
    }

    public function test_owner_opens_the_shop_and_chooses_its_link(): void
    {
        $this->merchant->update(['shop_enabled' => false, 'shop_slug' => null]);

        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/shop')->assertForbidden();

        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/shop')->assertOk()->assertJsonPath('data.suggested_slug', 'boutique-test');
        $this->patchJson('/api/v1/shop', ['shop_enabled' => true])->assertOk()
            ->assertJsonPath('data.shop_slug', 'boutique-test')
            ->assertJsonPath('data.url', url('/b/boutique-test'));
        $this->patchJson('/api/v1/shop', ['shop_slug' => 'Awa Mode Chic', 'shop_intro' => 'Pagnes et robes'])->assertOk()
            ->assertJsonPath('data.shop_slug', 'awa-mode-chic');
        $this->getJson('/api/v1/shop')->assertJsonPath('data.shop_fee_payer', 'recipient');
        $this->patchJson('/api/v1/shop', ['shop_fee_payer' => 'merchant'])->assertOk()->assertJsonPath('data.shop_fee_payer', 'merchant');
        $this->patchJson('/api/v1/shop', ['shop_fee_payer' => 'personne'])->assertJsonValidationErrors('shop_fee_payer');

        $other = $this->merchant->replicate(['shop_slug'])->fill(['phone' => '0599999999', 'shop_slug' => 'pris']);
        $other->save();
        $this->patchJson('/api/v1/shop', ['shop_slug' => 'pris'])->assertJsonValidationErrors('shop_slug');
    }

    public function test_public_page_lists_visible_products_and_delivery_zones(): void
    {
        $this->product('Ancien modèle', 5000, ['is_active' => false]);
        $this->product('Hors boutique', 5000, ['shop_visible' => false]);
        $this->zone('Expédition Bouaké')->update(['is_shipping' => true]);

        $page = $this->getJson('/api/v1/shops/BOUTIQUE-TEST')->assertOk()
            ->assertJsonPath('data.name', 'Boutique Test')
            ->assertJsonCount(2, 'data.products');
        $products = collect($page->json('data.products'))->keyBy('name');
        $this->assertNull($products['Robe wax']['available']);
        $this->assertSame(3, $products['Sac à main']['available']);
        $this->assertNotContains('Expédition Bouaké', collect($page->json('data.zones'))->pluck('name'));

        $this->merchant->update(['shop_enabled' => false]);
        $this->getJson('/api/v1/shops/boutique-test')->assertNotFound();
    }

    public function test_quote_and_cash_on_delivery_order(): void
    {
        Notification::fake();

        // Par défaut, le client de la boutique paie la livraison
        $quote = ['zone_id' => $this->yopougon->id, 'items' => [['product_id' => $this->dress->id, 'quantity' => 2]]];
        $this->postJson('/api/v1/shops/boutique-test/quote', $quote)
            ->assertOk()->assertJsonPath('data.items_total', 30000)->assertJsonPath('data.delivery_fee', 1500)
            ->assertJsonPath('data.fee_payer', 'recipient')->assertJsonPath('data.total', 31500);

        // Livraison offerte par le marchand sur sa boutique
        $this->merchant->update(['shop_fee_payer' => 'merchant']);
        $this->postJson('/api/v1/shops/boutique-test/quote', $quote)->assertJsonPath('data.fee_payer', 'merchant')->assertJsonPath('data.total', 30000);

        // Réglage propre à la boutique : indépendant de celui des autres courses du marchand
        $this->merchant->update(['shop_fee_payer' => 'recipient', 'default_fee_payer' => 'merchant']);
        $response = $this->postJson('/api/v1/shops/boutique-test/orders', $this->orderData([
            'items' => [['product_id' => $this->dress->id, 'quantity' => 2], ['product_id' => $this->bag->id, 'quantity' => 1]],
        ]))->assertCreated()->assertJsonPath('data.total', 39500);

        $order = Order::where('tracking_code', $response->json('data.tracking_code'))->firstOrFail();
        $this->assertSame('shop', $order->source);
        $this->assertSame('+2250506070809', $order->recipient_phone);
        $this->assertSame($this->cocody->id, $order->pickup_zone_id);
        $this->assertNull($order->pickup_hub_id);
        $this->assertSame(38000, $order->items_amount);
        $this->assertSame('recipient', $order->fee_payer->value);
        $this->assertStringContainsString('Appelez avant de passer', $order->merchant_note);
        $this->assertCount(2, $order->items);
        // Le sac (stock suivi) est réservé, la robe est un simple article
        $this->assertSame('reserved', $order->items->firstWhere('product_id', $this->bag->id)->stock_state);
        $this->assertNull($order->items->firstWhere('label', 'Robe wax')->product_id);
        $this->assertSame(1, $this->bag->levels()->first()->reserved);
        Notification::assertSentTo($this->merchantUser, ShopOrderReceived::class);
        Notification::assertNotSentTo($this->dispatcher, ShopOrderReceived::class);
    }

    public function test_stock_limits_and_warehouse_departure(): void
    {
        $this->postJson('/api/v1/shops/boutique-test/orders', $this->orderData(['items' => [['product_id' => $this->bag->id, 'quantity' => 5]]]))
            ->assertUnprocessable()->assertJsonPath('message', 'Il ne reste que 3 « Sac à main ».');

        // Produit stocké uniquement dans un entrepôt : le colis part de l'entrepôt
        $hub = Hub::create(['company_id' => $this->company->id, 'name' => 'Entrepôt Plateau', 'zone_id' => $this->plateau->id, 'address' => 'Rue du commerce']);
        $this->rule($this->grid, $this->plateau, $this->yopougon, 2000);
        $shoes = $this->product('Chaussures', 20000);
        $keeper = app(StockKeeper::class);
        $keeper->receive($this->admin, $shoes, $keeper->location($this->merchant, $hub), 2);

        $code = $this->postJson('/api/v1/shops/boutique-test/orders', $this->orderData(['items' => [['product_id' => $shoes->id, 'quantity' => 1]]]))
            ->assertCreated()->json('data.tracking_code');
        $order = Order::where('tracking_code', $code)->firstOrFail();
        $this->assertSame($hub->id, $order->pickup_hub_id);
        $this->assertSame($this->plateau->id, $order->pickup_zone_id);
        $this->assertSame(2000, $order->delivery_fee);

        // Article retiré de la boutique, robot, champs invalides
        $this->bag->update(['shop_visible' => false]);
        $this->postJson('/api/v1/shops/boutique-test/orders', $this->orderData(['items' => [['product_id' => $this->bag->id, 'quantity' => 1]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson('/api/v1/shops/boutique-test/orders', $this->orderData(['website' => 'http://spam']))->assertUnprocessable();
        $this->postJson('/api/v1/shops/boutique-test/orders', $this->orderData(['phone' => '12', 'zone_id' => 999999]))
            ->assertJsonValidationErrors(['phone', 'zone_id']);
    }

    public function test_product_photo_is_uploaded_and_served(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->merchantUser);

        $url = $this->postJson("/api/v1/products/{$this->dress->id}/photo", ['photo' => UploadedFile::fake()->image('robe.jpg', 400, 400)])
            ->assertOk()->json('data.photo_url');
        $this->assertStringStartsWith("/produits/{$this->dress->id}/photo?v=", $url);
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        $this->getJson('/api/v1/shops/boutique-test')->assertJsonFragment(['photo_url' => $url]);

        $this->postJson("/api/v1/products/{$this->dress->id}/photo", ['photo' => UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml')])
            ->assertJsonValidationErrors('photo');
        $this->deleteJson("/api/v1/products/{$this->dress->id}/photo")->assertNoContent();
        $this->assertNull($this->dress->fresh()->photo_path);

        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/products/{$this->dress->id}/photo", ['photo' => UploadedFile::fake()->image('robe.jpg', 400, 400)])->assertForbidden();
    }
}
