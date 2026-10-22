<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\MerchantLedgerEntry;
use App\Models\MerchantPayout;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\WebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * API publique, compte du marchand : /me, adresses webhook, reversements, preuves, origine des courses.
 * Ce que Jibiat Marchand utilise pour se connecter au nom d'un marchand.
 */
class PublicAccountApiTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        [, $this->key] = ApiKey::issue($this->merchant, 'Jibiat Marchand', ['orders:read', 'orders:write', 'webhooks:write', 'payouts:read'], $this->merchantUser);
    }

    private function api(string $method, string $uri, array $data = [], array $headers = [], ?string $key = null)
    {
        return $this->json($method, '/api/public/v1/'.$uri, $data, ['Authorization' => 'Bearer '.($key ?? $this->key), ...$headers]);
    }

    public function test_me_describes_the_merchant_the_company_and_the_key(): void
    {
        [, $readOnly] = ApiKey::issue($this->merchant, 'Lecture', ['orders:read'], $this->merchantUser);

        $this->api('GET', 'me', key: $readOnly)->assertOk()
            ->assertJsonPath('data.merchant.id', $this->merchant->id)
            ->assertJsonPath('data.merchant.business_name', $this->merchant->business_name)
            ->assertJsonPath('data.company.slug', $this->company->slug)
            ->assertJsonPath('data.key.scopes', ['orders:read'])
            ->assertJsonFragment(['webhook_events' => ['order.created', 'order.status_changed', 'order.incident', 'order.updated', 'payout.paid', 'stock.low']]);
    }

    public function test_webhooks_are_managed_by_api_with_the_secret_shown_once(): void
    {
        $created = $this->api('POST', 'webhooks', [
            'url' => 'https://awa.jibiat-marchand.com/webhooks/carriers/abc',
            'events' => ['order.created', 'order.status_changed', 'order.status_changed'],
        ])->assertCreated()->assertJsonPath('data.events', ['order.created', 'order.status_changed']);
        $this->assertMatchesRegularExpression('/^whsec_[A-Za-z0-9]{40}$/', $created->json('secret'));
        $this->assertSame($created->json('secret'), WebhookSubscription::find($created->json('data.id'))->secret);

        $this->api('GET', 'webhooks')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.secret');
        $this->api('POST', 'webhooks', ['url' => 'https://x.test/h', 'events' => ['order.lost']])->assertJsonValidationErrors('events.0');
        $this->api('POST', 'webhooks', ['url' => 'http://127.0.0.1/h', 'events' => ['order.created']])->assertStatus(422);

        // Portée exigée ; une adresse d'un autre marchand est introuvable
        [, $readOnly] = ApiKey::issue($this->merchant, 'Lecture', ['orders:read'], $this->merchantUser);
        $this->api('GET', 'webhooks', key: $readOnly)->assertForbidden();
        $other = $this->createMerchantWithKey();
        $this->api('DELETE', 'webhooks/'.$created->json('data.id'), key: $other)->assertNotFound();

        $this->api('DELETE', 'webhooks/'.$created->json('data.id'))->assertNoContent();
        $this->assertSame(0, WebhookSubscription::count());
    }

    public function test_payouts_list_and_detail_with_orders_for_reconciliation(): void
    {
        $order = $this->createOrder(['merchant_reference' => '#1042-1', 'items_amount' => 10000]);
        $payout = MerchantPayout::create([
            'company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'reference' => 'RV-261011-00001',
            'total_collected' => 10000, 'total_fees' => 1500, 'net_amount' => 8500, 'status' => 'paid', 'method' => 'wave',
            'transaction_ref' => 'WV-1', 'paid_at' => now(), 'created_by' => $this->admin->id,
        ]);
        foreach ([['cod_credit', 10000], ['delivery_fee', -1500]] as [$type, $amount]) {
            MerchantLedgerEntry::create(['company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'type' => $type, 'amount' => $amount, 'order_id' => $order->id, 'payout_id' => $payout->id]);
        }

        $this->api('GET', 'payouts')->assertOk()->assertJsonPath('data.0.reference', 'RV-261011-00001')->assertJsonPath('data.0.net_amount', 8500);
        $this->api('GET', 'payouts/RV-261011-00001')->assertOk()
            ->assertJsonPath('data.method', 'wave')
            ->assertJsonCount(2, 'data.entries')
            ->assertJsonPath('data.entries.0.type', 'cod_credit')
            ->assertJsonPath('data.entries.1.amount', -1500)
            ->assertJsonPath('data.entries.0.order.merchant_reference', '#1042-1')
            ->assertJsonPath('data.entries.0.order.tracking_code', $order->tracking_code);

        $this->api('GET', 'payouts/RV-INCONNU')->assertNotFound();
        $this->api('GET', 'payouts', key: $this->createMerchantWithKey(['payouts:read']))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_orders_expose_pickup_proof_photos_and_partner_origin(): void
    {
        Storage::fake('local');
        $created = $this->api('POST', 'orders', [
            'merchant_reference' => '#1042-1',
            'recipient_name' => 'Fatou Diallo',
            'recipient_phone' => '0506070809',
            'delivery_zone_id' => $this->yopougon->id,
            'pickup_zone_id' => $this->cocody->id,
            'pickup_address' => 'Boutique Awa Mode, Riviera 2',
            'pickup_contact_name' => 'Awa',
            'items_amount' => 15000,
        ], ['Idempotency-Key' => 'ship-1', 'X-Client-App' => 'jibiat-marchand'])->assertCreated()
            ->assertJsonPath('data.pickup.address', 'Boutique Awa Mode, Riviera 2')
            ->assertJsonPath('data.pickup.zone_id', $this->cocody->id)
            ->assertJsonPath('data.proof.photos', []);
        $order = Order::where('tracking_code', $created->json('data.tracking_code'))->sole();
        $this->assertSame('ecommerce_app', $order->source);

        Storage::disk('local')->put("orders/{$order->id}/colis.jpg", UploadedFile::fake()->image('colis.jpg')->getContent());
        OrderAttachment::create(['order_id' => $order->id, 'type' => 'photo_delivery', 'disk' => 'local', 'path' => "orders/{$order->id}/colis.jpg", 'mime_type' => 'image/jpeg']);
        OrderAttachment::create(['order_id' => $order->id, 'type' => 'document', 'disk' => 'local', 'path' => 'x.pdf', 'mime_type' => 'application/pdf']);

        $photos = $this->api('GET', "orders/{$order->tracking_code}")->assertOk()->assertJsonCount(1, 'data.proof.photos')->json('data.proof.photos');
        $this->assertSame('photo_delivery', $photos[0]['type']);

        // Lien signé : lisible sans clé, refusé une fois falsifié ou expiré
        $this->get($photos[0]['url'])->assertOk();
        $this->get($photos[0]['url'].'x')->assertForbidden();
        $this->travel(25)->hours();
        $this->get($photos[0]['url'])->assertForbidden();
    }

    /**
     * @param  list<string>  $scopes
     */
    private function createMerchantWithKey(array $scopes = ['webhooks:write']): string
    {
        $merchant = $this->merchant->replicate()->fill(['business_name' => 'Autre boutique', 'phone' => '+2250102030499']);
        $merchant->save();
        $user = $this->merchantUser->replicate()->fill(['merchant_id' => $merchant->id, 'phone' => '+2250102030498', 'email' => 'autre@example.test']);
        $user->save();

        return ApiKey::issue($merchant, 'Autre', $scopes, $user)[1];
    }
}
