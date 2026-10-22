<?php

namespace Tests\Feature\Api;

use App\Enums\NotificationEvent;
use App\Enums\OrderStatus;
use App\Enums\WhatsAppTemplate;
use App\Jobs\SendWebhook;
use App\Models\ApiKey;
use App\Models\MerchantPayout;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Notifications\OrderAlert;
use App\Notifications\WebhookDisabled;
use App\Services\Messaging\Messenger;
use App\Services\Orders\DeliveryDay;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Synchronisation avec l'application du marchand (Jibiat Marchand) : rien ne doit changer sans qu'elle le sache.
 *  - « order.updated » pour un changement sans changement de statut (date, adresse, retour demandé…) ;
 *  - décision attendue et retour demandé dans la course ;
 *  - journée de livraison, rattrapage des reversements ;
 *  - pas d'alerte en double dans la cloche du marchand ; adresse webhook morte coupée.
 */
class PublicSyncTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        config(['services.webhooks.allow_private_targets' => true]);
        [, $this->key] = ApiKey::issue($this->merchant, 'Jibiat Marchand', ['orders:read', 'orders:write', 'payouts:read'], $this->merchantUser);
    }

    private function api(string $method, string $uri, array $data = [], array $headers = [])
    {
        return $this->json($method, '/api/public/v1/'.$uri, $data, ['Authorization' => 'Bearer '.$this->key, ...$headers]);
    }

    private function subscribe(): WebhookSubscription
    {
        return WebhookSubscription::create([
            'company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'url' => 'https://boutique.example/hook',
            'events' => ['order.created', 'order.status_changed', 'order.incident', 'order.updated'], 'secret' => 'whsec_test', 'is_active' => true,
        ]);
    }

    /** @return list<array{event: string, fields: list<string>|null, date: ?string}> */
    private function sent(): array
    {
        return WebhookDelivery::orderBy('id')->get()->map(fn (WebhookDelivery $d) => [
            'event' => $d->event,
            'fields' => $d->payload['data']['change']['fields'] ?? null,
            'date' => $d->payload['data']['order']['delivery']['scheduled_date'] ?? null,
        ])->all();
    }

    public function test_changes_without_a_status_change_are_sent_as_order_updated(): void
    {
        $this->subscribe();
        $order = $this->createOrder();
        $order->forceFill(['status' => OrderStatus::Confirmed])->save();
        WebhookDelivery::query()->delete();

        // Date et adresse modifiées par le marchand
        $tomorrow = today()->addDay()->toDateString();
        app(OrderService::class)->update($this->merchantUser, $order, ['delivery_scheduled_date' => $tomorrow, 'delivery_address' => 'Siporex, face pharmacie']);
        $this->assertSame([['event' => 'order.updated', 'fields' => ['delivery_address', 'delivery_scheduled_date'], 'date' => $tomorrow]], $this->sentSorted());

        // Journée fermée par l'agence : la course passe au lendemain
        WebhookDelivery::query()->delete();
        $order->forceFill(['delivery_scheduled_date' => null])->saveQuietly();
        $this->company->update(['validation_mode' => 'rules']);
        app(DeliveryDay::class)->close($this->company->fresh(), $this->dispatcher, postpone: true);
        $this->assertSame('order.updated', $this->sent()[0]['event']);
        $this->assertSame(['delivery_scheduled_date'], $this->sent()[0]['fields']);
        $this->assertNotNull($this->sent()[0]['date']);

        // Retour demandé sur un colis récupéré
        WebhookDelivery::query()->delete();
        $order->forceFill(['status' => OrderStatus::DeliveryFailed])->saveQuietly();
        app(OrderService::class)->requestReturn($this->merchantUser, $order->fresh(), 'Le client a annulé');
        $this->assertSame([['event' => 'order.updated', 'fields' => ['return_requested'], 'date' => $this->sent()[0]['date']]], $this->sent());

        // Une simple note interne ne déclenche rien
        WebhookDelivery::query()->delete();
        app(OrderService::class)->addNote($this->dispatcher, $order->fresh(), 'Vérifié au dépôt', visibleToMerchant: false);
        $this->assertSame([], $this->sent());
    }

    /** Champs triés pour une comparaison stable */
    private function sentSorted(): array
    {
        return array_map(fn ($s) => [...$s, 'fields' => $s['fields'] === null ? null : collect($s['fields'])->sort()->values()->all()], $this->sent());
    }

    public function test_order_shows_decision_needed_and_return_requested(): void
    {
        $order = $this->createOrder();
        $order->forceFill(['status' => OrderStatus::DeliveryFailed])->save();

        $this->api('GET', "orders/{$order->tracking_code}")->assertOk()
            ->assertJsonPath('data.needs_decision', true)
            ->assertJsonPath('data.return_requested', false);

        app(OrderService::class)->requestReturn($this->merchantUser, $order->fresh());
        $this->api('GET', "orders/{$order->tracking_code}")
            ->assertJsonPath('data.needs_decision', false)
            ->assertJsonPath('data.return_requested', true);
    }

    public function test_delivery_day_and_payouts_paid_since(): void
    {
        $this->company->update(['validation_mode' => 'rules', 'daily_cutoff_time' => '16:00', 'timezone' => 'Africa/Abidjan']);
        $this->travelTo(now('Africa/Abidjan')->setTime(10, 0));
        $this->api('GET', 'delivery-day')->assertOk()
            ->assertJsonPath('data.closed', false)
            ->assertJsonPath('data.earliest_delivery_date', today()->toDateString());

        $this->travelTo(now()->setTime(17, 0));
        $this->api('GET', 'delivery-day')
            ->assertJsonPath('data.closed', true)
            ->assertJsonPath('data.reason', 'cutoff')
            ->assertJsonPath('data.earliest_delivery_date', today()->addDay()->toDateString());

        foreach ([['RV-1', now()->subDays(40)], ['RV-2', now()->subDay()]] as [$reference, $paidAt]) {
            MerchantPayout::create([
                'company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'reference' => $reference,
                'total_collected' => 0, 'total_fees' => 0, 'net_amount' => 0, 'status' => 'paid', 'paid_at' => $paidAt, 'created_by' => $this->admin->id,
            ]);
        }
        $this->api('GET', 'payouts?status=paid&paid_since='.now()->subDays(7)->toDateString())->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'RV-2');
    }

    public function test_orders_sent_with_notify_merchant_false_do_not_ring_the_merchant_twice(): void
    {
        Notification::fake();
        $base = ['recipient_phone' => '0707070707', 'delivery_zone_id' => $this->yopougon->id, 'items_amount' => 5000];

        $muted = $this->api('POST', 'orders', [...$base, 'notify_merchant' => false], ['Idempotency-Key' => 'a-1', 'X-Client-App' => 'jibiat-marchand'])->assertCreated()->json('data.tracking_code');
        $normal = $this->api('POST', 'orders', $base, ['Idempotency-Key' => 'a-2'])->assertCreated()->json('data.tracking_code');

        foreach ([$muted, $normal] as $code) {
            $order = Order::where('tracking_code', $code)->first();
            app(OrderWorkflow::class)->transition($this->dispatcher, $order, OrderStatus::Rejected, ['note' => 'Zone non desservie']);
        }

        // Seule la course « normale » prévient le marchand ; l'agence est prévenue dans les deux cas
        Notification::assertSentToTimes($this->merchantUser, OrderAlert::class, 1);
        Notification::assertSentTo($this->merchantUser, OrderAlert::class, fn (OrderAlert $n) => $n->order->tracking_code === $normal);

        // Pas de WhatsApp ni de notification push en double non plus
        OutboundMessage::query()->delete();
        foreach ([$muted, $normal] as $code) {
            $order = Order::where('tracking_code', $code)->first();
            app(Messenger::class)->toMerchant($this->merchant, NotificationEvent::OrderIncident, WhatsAppTemplate::DeliveryIncident,
                [$this->merchant->business_name, $code, 'Awa', 'livraison impossible', 'https://exemple.ci'], $order);
        }
        $this->assertSame([Order::where('tracking_code', $normal)->value('id')],
            OutboundMessage::where('recipient_type', 'merchant')->pluck('order_id')->all());
    }

    public function test_a_dead_webhook_address_is_disabled_and_the_merchant_told(): void
    {
        Notification::fake();
        $subscription = $this->subscribe();
        $subscription->forceFill(['consecutive_failures' => SendWebhook::DISABLE_AFTER - 1])->save();
        Http::fake(['*' => Http::response('Erreur', 500)]);

        $order = $this->createOrder();
        $delivery = WebhookDelivery::where('event', 'order.created')->firstOrFail();
        // Dernière tentative épuisée
        $delivery->forceFill(['attempts' => count(SendWebhook::BACKOFF)])->save();
        (new SendWebhook($delivery->id))->handle();

        $this->assertFalse($subscription->fresh()->is_active);
        Notification::assertSentTo($this->merchantUser, WebhookDisabled::class);
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertNotNull($order);
    }
}
