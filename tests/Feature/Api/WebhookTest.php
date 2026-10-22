<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Jobs\SendWebhook;
use App\Models\IncidentReason;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\MerchantPayout;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Webhooks sortants : abonnement, signature HMAC, relances, journal et renvoi.
 */
class WebhookTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const URL = 'https://boutique.example/webhooks/livraison';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        config(['services.webhooks.allow_private_targets' => true]);
    }

    private function subscribe(array $events = ['order.created', 'order.status_changed', 'order.incident']): array
    {
        Sanctum::actingAs($this->merchantUser);

        $response = $this->postJson('/api/v1/webhooks', ['url' => self::URL, 'events' => $events])->assertCreated();

        return [WebhookSubscription::findOrFail($response->json('data.id')), $response->json('secret')];
    }

    public function test_merchant_subscribes_with_a_secret_shown_once(): void
    {
        Sanctum::actingAs($this->merchantUser);

        $this->postJson('/api/v1/webhooks', ['url' => 'http://boutique.example/hook', 'events' => ['order.created']])
            ->assertJsonValidationErrors('url');
        $this->postJson('/api/v1/webhooks', ['url' => self::URL, 'events' => ['order.deleted']])
            ->assertJsonValidationErrors('events.0');

        [$subscription, $secret] = $this->subscribe();
        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertSame($secret, $subscription->secret);
        $this->assertNotSame($secret, $subscription->getRawOriginal('secret'));

        $this->getJson('/api/v1/webhooks')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['secret' => $secret])
            ->assertJsonCount(6, 'events');

        $newSecret = $this->postJson("/api/v1/webhooks/{$subscription->id}/secret")->assertOk()->json('secret');
        $this->assertNotSame($secret, $newSecret);

        // Un autre marchand n'y touche pas
        $other = $this->userWithRole(Role::MerchantOwner, ['merchant_id' => Merchant::factory()->create(['company_id' => $this->company->id])->id]);
        Sanctum::actingAs($other);
        $this->patchJson("/api/v1/webhooks/{$subscription->id}", ['is_active' => false])->assertForbidden();
        $this->getJson('/api/v1/webhooks')->assertJsonCount(0, 'data');
    }

    public function test_private_addresses_are_refused(): void
    {
        config(['services.webhooks.allow_private_targets' => false]);
        Sanctum::actingAs($this->merchantUser);

        foreach (['https://localhost/hook', 'https://127.0.0.1/hook', 'https://10.0.0.5/hook', 'https://[::1]/hook', 'https://192.168.1.10/hook'] as $url) {
            $this->postJson('/api/v1/webhooks', ['url' => $url, 'events' => ['order.created']])->assertJsonValidationErrors('url');
        }
    }

    public function test_order_events_are_signed_and_delivered(): void
    {
        [$subscription, $secret] = $this->subscribe();
        Http::fake([self::URL => Http::response(['ok' => true])]);

        $order = $this->createOrder(['merchant_reference' => 'CMD-77']);

        Http::assertSent(function (HttpRequest $request) use ($secret, $order) {
            $timestamp = $request->header('X-Webhook-Timestamp')[0];
            $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), $secret);

            return $request->url() === self::URL
                && $request->header('X-Webhook-Event')[0] === 'order.created'
                && hash_equals($expected, $request->header('X-Webhook-Signature')[0])
                && $request['data']['order']['tracking_code'] === $order->tracking_code
                && $request['data']['order']['merchant_reference'] === 'CMD-77';
        });

        $delivery = WebhookDelivery::first();
        $this->assertSame('delivered', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(200, $delivery->response_code);

        // Changement de statut, puis incident (échec de livraison)
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up']);
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery']);
        $reason = IncidentReason::where('applies_to', '!=', 'pickup')->where('requires_date', false)->first();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivery_failed', 'incident_reason_id' => $reason->id])->assertOk();

        $events = WebhookDelivery::orderBy('id')->pluck('event')->all();
        $this->assertContains('order.status_changed', $events);
        $this->assertSame('order.incident', end($events));
        $incident = WebhookDelivery::where('event', 'order.incident')->first();
        $this->assertSame('delivery_failed', $incident->payload['data']['change']['to_status']);
        $this->assertSame($reason->label, $incident->payload['data']['change']['incident']);

        // Abonnement limité : seuls les événements choisis partent
        $subscription->update(['events' => ['payout.paid']]);
        $count = WebhookDelivery::count();
        $this->createOrder();
        $this->assertSame($count, WebhookDelivery::count());
    }

    public function test_failures_are_retried_with_backoff_then_marked_failed(): void
    {
        [$subscription] = $this->subscribe(['order.created']);
        Http::fake([self::URL => Http::sequence()->push('Erreur', 500)->push('Erreur', 500)->push('', 204)]);
        Queue::fake();

        $this->createOrder();
        Queue::assertPushed(SendWebhook::class, 1);

        $delivery = WebhookDelivery::first();
        (new SendWebhook($delivery->id))->handle();

        $delivery->refresh();
        $this->assertSame('pending', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(500, $delivery->response_code);
        $this->assertSame('Réponse HTTP 500', $delivery->error);
        $this->assertNotNull($delivery->next_retry_at);
        Queue::assertPushed(SendWebhook::class, fn (SendWebhook $job) => $job->deliveryId === $delivery->id && $job->delay === 60);

        // Après la dernière tentative : échec définitif
        $delivery->forceFill(['attempts' => count(SendWebhook::BACKOFF)])->save();
        (new SendWebhook($delivery->id))->handle();
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame(1, $subscription->fresh()->consecutive_failures);

        // Renvoi manuel, une fois le site du marchand réparé
        Sanctum::actingAs($this->merchantUser);
        $this->postJson("/api/v1/webhook-deliveries/{$delivery->id}/redeliver")->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->assertSame(0, $subscription->fresh()->consecutive_failures);

        $this->getJson("/api/v1/webhooks/{$subscription->id}/deliveries")->assertOk()->assertJsonPath('data.0.status', 'delivered');
    }

    public function test_ping_and_payout_events(): void
    {
        [$subscription] = $this->subscribe(['payout.paid']);
        Http::fake([self::URL => Http::response('ok')]);

        $this->postJson("/api/v1/webhooks/{$subscription->id}/test")->assertOk()
            ->assertJsonPath('data.event', 'ping')
            ->assertJsonPath('data.status', 'delivered');

        // Reversement payé
        $order = $this->createOrder();
        MerchantLedgerEntry::create([
            'company_id' => $this->company->id, 'merchant_id' => $this->merchant->id,
            'type' => 'adjustment', 'amount' => 5000, 'description' => 'Test',
        ]);
        Sanctum::actingAs($this->admin);
        $payoutId = $this->postJson('/api/v1/finance/payouts', ['merchant_id' => $this->merchant->id])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/finance/payouts/{$payoutId}/pay", ['method' => 'wave'])->assertOk();

        $delivery = WebhookDelivery::where('event', 'payout.paid')->first();
        $this->assertSame(MerchantPayout::find($payoutId)->reference, $delivery->payload['data']['payout']['reference']);
        $this->assertSame(5000, $delivery->payload['data']['payout']['net_amount']);
        $this->assertSame('delivered', $delivery->status);
        $this->assertNotNull($order);

        // Adresse désactivée : plus rien ne part
        $subscription->update(['is_active' => false]);
        $this->assertSame(0, WebhookDelivery::where('event', 'order.created')->count());
    }
}
