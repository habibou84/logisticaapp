<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\ApiKey;
use App\Models\Merchant;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * API publique des marchands : clés et portées, idempotence, quota, contrat des courses.
 */
class PublicApiTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        [, $this->key] = ApiKey::issue($this->merchant, 'Boutique en ligne', ['orders:read', 'orders:write', 'stock:read'], $this->merchantUser);
    }

    private function api(string $method, string $uri, array $data = [], array $headers = [], ?string $key = null)
    {
        return $this->json($method, '/api/public/v1/'.$uri, $data, ['Authorization' => 'Bearer '.($key ?? $this->key), ...$headers]);
    }

    private function newOrder(array $data = [], string $idempotency = 'cmd-1001')
    {
        return $this->api('POST', 'orders', [
            'merchant_reference' => 'CMD-1001',
            'recipient_name' => 'Jean Kouadio',
            'recipient_phone' => '0707070707',
            'delivery_zone_id' => $this->yopougon->id,
            'items_amount' => 10000,
            'fee_payer' => 'recipient',
            ...$data,
        ], ['Idempotency-Key' => $idempotency]);
    }

    public function test_merchant_issues_lists_and_revokes_keys(): void
    {
        Sanctum::actingAs($this->merchantUser);

        $response = $this->postJson('/api/v1/api-keys', ['name' => 'Site', 'scopes' => ['orders:read']])->assertCreated();
        $plain = $response->json('key');
        $this->assertMatchesRegularExpression('/^lv_[a-z0-9]{8}_[A-Za-z0-9]{40}$/', $plain);
        $this->assertStringNotContainsString(substr($plain, 12), json_encode(ApiKey::find($response->json('data.id'))));

        $this->getJson('/api/v1/api-keys')->assertOk()->assertJsonCount(2, 'data')->assertJsonCount(5, 'scopes');
        $this->postJson('/api/v1/api-keys', ['name' => 'X', 'scopes' => ['admin:all']])->assertJsonValidationErrors('scopes.0');

        $this->api('GET', 'orders', key: $plain)->assertOk();
        $this->deleteJson('/api/v1/api-keys/'.$response->json('data.id'))->assertOk()->assertJsonPath('data.is_active', false);
        $this->api('GET', 'orders', key: $plain)->assertUnauthorized();

        // Un employé du marchand ne gère pas les clés ; l'administration le fait pour un marchand
        Sanctum::actingAs($this->userWithRole(Role::MerchantStaff, ['merchant_id' => $this->merchant->id]));
        $this->getJson('/api/v1/api-keys')->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/api-keys', ['name' => 'Par l\'agence', 'scopes' => ['orders:read']])->assertJsonValidationErrors('merchant_id');
        $this->postJson('/api/v1/api-keys', ['name' => 'Par l\'agence', 'scopes' => ['orders:read'], 'merchant_id' => $this->merchant->id])->assertCreated();
    }

    public function test_keys_are_checked_and_scoped(): void
    {
        $this->json('GET', '/api/public/v1/zones')->assertUnauthorized();
        $this->api('GET', 'zones', key: 'lv_abcdefgh_'.str_repeat('x', 40))->assertUnauthorized();
        $this->json('GET', '/api/public/v1/zones', [], ['X-Api-Key' => $this->key])->assertOk()->assertJsonFragment(['name' => 'Yopougon']);

        [, $readOnly] = ApiKey::issue($this->merchant, 'Lecture', ['orders:read'], $this->merchantUser);
        $this->api('GET', 'orders', key: $readOnly)->assertOk();
        $this->newOrder()->assertCreated();
        $this->api('POST', 'orders', ['recipient_phone' => '0707070707'], ['Idempotency-Key' => 'x'], $readOnly)->assertForbidden();
        $this->api('GET', 'products', key: $readOnly)->assertForbidden();

        [$expired, $expiredKey] = ApiKey::issue($this->merchant, 'Ancienne', ['orders:read'], $this->merchantUser);
        $expired->forceFill(['expires_at' => now()->subDay()])->save();
        $this->api('GET', 'orders', key: $expiredKey)->assertUnauthorized();

        // Marchand suspendu : plus d'accès
        $this->merchant->update(['status' => 'suspended']);
        $this->api('GET', 'orders')->assertForbidden();
    }

    public function test_order_creation_is_idempotent(): void
    {
        $first = $this->newOrder()->assertCreated()
            ->assertJsonPath('data.merchant_reference', 'CMD-1001')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amounts.cod_amount', 11500)
            ->assertJsonPath('data.delivery.zone_name', 'Yopougon');
        $this->assertStringEndsWith('/suivi/'.$first->json('data.tracking_code'), $first->json('data.tracking_url'));
        $this->assertSame('api', Order::first()->source);

        // Relance réseau : même réponse, pas de doublon
        $this->newOrder()->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.tracking_code', $first->json('data.tracking_code'));
        $this->assertSame(1, Order::count());

        $this->newOrder(['items_amount' => 9000])->assertStatus(422)->assertJsonPath('message', 'Cette clé d\'idempotence a déjà servi pour une requête différente.');
        $this->api('POST', 'orders', ['recipient_phone' => '0707070707'])->assertJsonValidationErrors('Idempotency-Key');

        // Erreur de validation mémorisée elle aussi
        $this->newOrder(['delivery_zone_id' => 999999], 'cmd-bad')->assertJsonValidationErrors('delivery_zone_id');
        $this->newOrder(['delivery_zone_id' => 999999], 'cmd-bad')->assertHeader('Idempotent-Replayed', 'true');

        $this->newOrder(['merchant_reference' => 'CMD-1002'], 'cmd-1002')->assertCreated();
        $this->assertSame(2, Order::count());
    }

    public function test_zones_can_be_given_by_name(): void
    {
        $this->rule($this->grid, $this->cocody, $this->angre, 1200);
        $this->zone('Riviera 2', $this->cocody);
        $this->zone('Riviera 3', $this->cocody);

        $this->newOrder(['delivery_zone_id' => null, 'delivery_zone' => 'Yopougon Siporex'], 'nom-1')->assertCreated()
            ->assertJsonPath('data.delivery.zone_id', $this->yopougon->id);
        $this->newOrder(['delivery_zone_id' => null, 'delivery_zone' => 'cocody angre'], 'nom-2')->assertCreated()
            ->assertJsonPath('data.delivery.zone_id', $this->angre->id);

        $this->newOrder(['delivery_zone_id' => null, 'delivery_zone' => 'Marcory'], 'nom-3')->assertUnprocessable()
            ->assertJsonPath('errors.delivery_zone.0', fn ($m) => str_contains($m, 'Zone inconnue'))
            ->assertJsonMissingValidationErrors('delivery_zone_id');
        $this->newOrder(['delivery_zone_id' => null, 'delivery_zone' => 'Riviera'], 'nom-4')->assertUnprocessable()
            ->assertJsonPath('errors.delivery_zone.0', fn ($m) => str_contains($m, 'Riviera 2') && str_contains($m, 'Riviera 3'));
        $this->newOrder(['delivery_zone_id' => null], 'nom-5')->assertJsonValidationErrors('delivery_zone_id');

        // L'identifiant reste prioritaire, et le devis accepte aussi le nom
        $this->newOrder(['delivery_zone' => 'Marcory'], 'nom-6')->assertCreated();
        $this->api('POST', 'quotes', ['delivery_zone' => 'Yopougon'])->assertOk()->assertJsonPath('data.total', 1500);
    }

    public function test_orders_are_read_tracked_and_cancelled(): void
    {
        $code = $this->newOrder()->json('data.tracking_code');
        $other = $this->createOrder([], null, Merchant::factory()->create(['company_id' => $this->company->id, 'pickup_zone_id' => $this->cocody->id]));

        $this->api('GET', 'orders')->assertOk()->assertJsonCount(1, 'data');
        $this->api('GET', 'orders?merchant_reference=CMD-1001')->assertJsonCount(1, 'data');
        $this->api('GET', 'orders?updated_since='.urlencode(now()->addHour()->toIso8601String()))->assertJsonCount(0, 'data');
        $this->api('GET', "orders/{$other->tracking_code}")->assertNotFound();

        $this->api('GET', 'orders/'.strtolower($code))->assertOk()
            ->assertJsonPath('data.events.0.type', 'created')
            ->assertJsonMissingPath('data.assignments');

        $this->api('POST', "orders/{$code}/cancel", ['reason' => 'Client injoignable'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->api('POST', "orders/{$code}/cancel")->assertJsonValidationErrors('status');

        $this->api('GET', 'reports/summary')->assertOk()->assertJsonPath('data.counts.total', 1);
        $this->api('POST', 'quotes', ['delivery_zone_id' => $this->yopougon->id])->assertOk()->assertJsonPath('data.total', 1500);
        $this->api('GET', 'products')->assertOk();
    }

    public function test_requests_are_rate_limited_per_key(): void
    {
        config(['services.public_api.rate_limit' => 3]);

        $this->api('GET', 'zones')->assertOk()->assertHeader('X-RateLimit-Limit', 3);
        $this->api('GET', 'zones')->assertOk();
        $this->api('GET', 'zones')->assertOk();
        $this->api('GET', 'zones')->assertStatus(429);

        // Une autre clé a son propre quota
        [, $other] = ApiKey::issue($this->merchant, 'Autre', ['orders:read'], $this->merchantUser);
        $this->api('GET', 'zones', key: $other)->assertOk();
    }
}
