<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\WebhookEvent;
use App\Models\ApiKey;
use App\Models\IncidentReason;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\User;
use App\Models\WebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Jibiat\Socle\Contract\Contract;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Contrat avec Jibiat Marchand (habibou84/socle, contract/v1) : les vraies réponses de l'API publique et les vrais
 * webhooks envoyés respectent les schémas partagés. Un champ retiré, renommé ou d'un autre type casse ce test.
 */
class PublicContractTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        config(['services.webhooks.allow_private_targets' => true]);
        [, $this->key] = ApiKey::issue($this->merchant, 'Jibiat Marchand', ['orders:read', 'orders:write', 'webhooks:write', 'payouts:read'], $this->merchantUser);
    }

    private function api(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json($method, '/api/public/v1/'.$uri, $data, ['Authorization' => 'Bearer '.$this->key, 'X-Client-App' => 'jibiat-marchand', ...$headers]);
    }

    private function assertContract(string $schema, mixed $data, string $what = ''): void
    {
        $this->assertSame([], Contract::validate($schema, $data), trim("{$schema} {$what}"));
    }

    private function move(Order $order, User $actor, string $status, array $extra = []): void
    {
        Sanctum::actingAs($actor);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => $status, ...$extra])->assertOk();
        $this->app['auth']->forgetGuards();
    }

    private function pickedUp(): Order
    {
        $order = $this->createOrder(['items_amount' => 15000]);
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();
        $this->move($order, $this->courierA->user, 'picked_up');

        return $order->fresh();
    }

    private function failed(): Order
    {
        $order = $this->pickedUp();
        $this->move($order, $this->courierB->user, 'out_for_delivery');
        $reason = IncidentReason::where('applies_to', '!=', 'pickup')->where('requires_date', false)->where('triggers_return', false)->first();
        $this->move($order, $this->courierB->user, 'delivery_failed', ['incident_reason_id' => $reason->id]);

        return $order->fresh();
    }

    public function test_statuses_and_events_are_those_of_the_contract(): void
    {
        $this->assertSame(Contract::ORDER_STATUSES, OrderStatus::values(OrderStatus::cases()));
        $this->assertSame(Contract::WEBHOOK_EVENTS, WebhookEvent::values());
        $this->api('GET', 'me')->assertJsonPath('data.webhook_events', Contract::WEBHOOK_EVENTS);
    }

    public function test_order_responses_follow_the_contract_at_every_step(): void
    {
        Storage::fake('local');
        $created = $this->api('POST', 'orders', [
            'merchant_reference' => '#1001-1', 'recipient_name' => 'Fatou Diallo', 'recipient_phone' => '0506070809',
            'delivery_zone_id' => $this->yopougon->id, 'pickup_zone_id' => $this->cocody->id, 'pickup_address' => 'Riviera 2',
            'items_amount' => 31500, 'delivery_scheduled_date' => today()->addDay()->toDateString(),
        ], ['Idempotency-Key' => 'contract-1'])->assertCreated();
        $this->assertContract('order-response', $created->json(), 'création');
        $code = $created->json('data.tracking_code');

        $this->assertContract('order-response', $this->api('PATCH', "orders/{$code}", ['delivery_landmark' => 'Portail bleu'])->assertOk()->json(), 'modification');
        $this->assertContract('order-list', $this->api('GET', 'orders')->assertOk()->json());

        // Récupérée, en échec (décision attendue), reportée, retour demandé, livrée avec photo
        $this->assertContract('order-response', $this->api('GET', 'orders/'.$this->pickedUp()->tracking_code)->json(), 'récupérée');
        $failed = $this->failed();
        $this->assertContract('order-response', $this->api('GET', "orders/{$failed->tracking_code}")->assertJsonPath('data.needs_decision', true)->json(), 'en échec');
        $this->assertContract('order-response', $this->api('POST', "orders/{$failed->tracking_code}/decision", ['decision' => 'redeliver', 'date' => today()->addDay()->toDateString()])->assertOk()->json(), 'reportée');
        $this->assertContract('order-response', $this->api('POST', 'orders/'.$this->failed()->tracking_code.'/decision', ['decision' => 'return'])->assertOk()->json(), 'retour');
        $this->assertContract('order-response', $this->api('POST', 'orders/'.$this->pickedUp()->tracking_code.'/return-request')->assertOk()->json(), 'retour demandé');

        $delivered = $this->pickedUp();
        $this->move($delivered, $this->courierB->user, 'out_for_delivery');
        $delivered->refresh();
        $this->move($delivered, $this->courierB->user, 'delivered', ['delivery_code' => $delivered->delivery_code, 'collected_amount' => 16500]);
        Storage::disk('local')->put("orders/{$delivered->id}/colis.jpg", UploadedFile::fake()->image('colis.jpg')->getContent());
        OrderAttachment::create(['order_id' => $delivered->id, 'type' => 'photo_delivery', 'disk' => 'local', 'path' => "orders/{$delivered->id}/colis.jpg", 'mime_type' => 'image/jpeg']);
        $response = $this->api('GET', "orders/{$delivered->tracking_code}")->assertJsonPath('data.status', 'delivered')->assertJsonCount(1, 'data.proof.photos');
        $this->assertContract('order-response', $response->json(), 'livrée');
    }

    public function test_account_and_reference_responses_follow_the_contract(): void
    {
        $this->assertContract('me', $this->api('GET', 'me')->assertOk()->json());
        $this->assertContract('zones', $this->api('GET', 'zones')->assertOk()->json());
        $this->assertContract('delivery-day', $this->api('GET', 'delivery-day')->assertOk()->json());
        $this->assertContract('quote', $this->api('POST', 'quotes', ['delivery_zone_id' => $this->yopougon->id, 'pickup_zone_id' => $this->cocody->id])->assertOk()->json());
        $this->assertContract('webhook-created', $this->api('POST', 'webhooks', ['url' => 'https://awa.jibiat-marchand.com/webhooks/carriers/abc', 'events' => Contract::WEBHOOK_EVENTS])->assertCreated()->json());
        $this->assertContract('error', $this->api('POST', 'orders', [], ['Idempotency-Key' => 'contract-err'])->assertStatus(422)->json(), 'validation');
        $this->assertContract('error', $this->api('GET', 'orders/LV-INCONNU')->assertNotFound()->json(), 'introuvable');

        // Reversement payé : liste, détail et webhook
        $hooks = $this->captureWebhooks(['payout.paid']);
        MerchantLedgerEntry::create(['company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'type' => 'adjustment', 'amount' => 5000, 'description' => 'Test']);
        Sanctum::actingAs($this->admin);
        $payoutId = $this->postJson('/api/v1/finance/payouts', ['merchant_id' => $this->merchant->id])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/finance/payouts/{$payoutId}/pay", ['method' => 'wave'])->assertOk();
        $this->app['auth']->forgetGuards();

        $list = $this->api('GET', 'payouts?status=paid')->assertOk()->assertJsonCount(1, 'data');
        $this->assertContract('payout-list', $list->json());
        $this->assertContract('payout', $this->api('GET', 'payouts/'.$list->json('data.0.reference'))->assertOk()->json());
        $this->assertSame(['payout.paid'], array_column($hooks(), 'event'));
        $this->assertContract('payout-webhook', $hooks()[0]);
    }

    public function test_webhooks_sent_follow_the_contract(): void
    {
        $hooks = $this->captureWebhooks(Contract::ORDER_EVENTS);

        $order = $this->pickedUp();
        $this->api('PATCH', "orders/{$order->tracking_code}", ['delivery_landmark' => 'Portail bleu'])->assertOk();
        $this->move($order, $this->courierB->user, 'out_for_delivery');
        $reason = IncidentReason::where('applies_to', '!=', 'pickup')->where('requires_date', false)->where('triggers_return', false)->first();
        $this->move($order, $this->courierB->user, 'delivery_failed', ['incident_reason_id' => $reason->id]);
        $this->api('POST', "orders/{$order->tracking_code}/decision", ['decision' => 'redeliver', 'date' => today()->addDay()->toDateString()])->assertOk();

        $sent = $hooks();
        $this->assertEqualsCanonicalizing(Contract::ORDER_EVENTS, collect($sent)->pluck('event')->unique()->values()->all());
        foreach ($sent as $payload) {
            $this->assertContract('order-webhook', $payload, $payload['event']);
        }
    }

    /**
     * Abonne Jibiat Marchand aux événements et renvoie, à la demande, les corps réellement envoyés (signés).
     *
     * @param  list<string>  $events
     * @return callable(): list<array<string, mixed>>
     */
    private function captureWebhooks(array $events): callable
    {
        WebhookSubscription::create([
            'company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'url' => 'https://awa.jibiat-marchand.com/hook',
            'events' => $events, 'secret' => 'whsec_contract', 'is_active' => true,
        ]);
        Http::fake(['awa.jibiat-marchand.com/*' => Http::response('ok')]);

        return fn () => Http::recorded(fn (Request $r) => str_contains($r->url(), 'awa.jibiat-marchand.com/hook'))
            ->map(fn (array $pair) => json_decode($pair[0]->body(), true))->values()->all();
    }
}
