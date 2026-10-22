<?php

namespace Tests\Feature\Api;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\WhatsAppTemplate;
use App\Jobs\SendOutboundMessage;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Messaging\Gateways\SmsGateway;
use App\Services\Messaging\Gateways\WhatsAppGateway;
use App\Services\Messaging\Messenger;
use App\Services\Push\WebPush;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Messages aux e-commerçants : notification sur leur téléphone d'abord (gratuite),
 * WhatsApp quand l'application n'y est pas activée ou que la notification n'arrive pas.
 */
class MerchantPushTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/merchant-phone';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();

        $keys = WebPush::generateVapidKeys();
        config(['services.webpush.public_key' => $keys['public_key'], 'services.webpush.private_key' => $keys['private_key']]);
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    private function subscribeMerchantPhone(): void
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($key)['ec'];
        $public = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

        $this->as($this->merchantUser)->postJson('/api/v1/push/subscriptions', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => WebPush::b64($public), 'auth' => WebPush::b64(random_bytes(16))],
        ])->assertCreated();
    }

    private function deliver(Order $order): void
    {
        $this->as($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        $this->as($this->courierA->user)->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        $this->as($this->courierB->user)->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered', 'delivery_code' => $order->fresh()->delivery_code])->assertOk();
    }

    /**
     * @return list<string> « canal:modèle » des messages au marchand
     */
    private function merchantMessages(): array
    {
        return OutboundMessage::where('recipient_type', 'merchant')->orderBy('id')->get()
            ->map(fn (OutboundMessage $m) => $m->channel->value.':'.$m->template_name)->all();
    }

    public function test_merchant_with_the_app_gets_a_free_notification_instead_of_whatsapp(): void
    {
        Http::fake([self::ENDPOINT => Http::response('', 201)]);
        $this->subscribeMerchantPhone();

        $this->deliver($this->createOrder());

        // « Récupéré » est désactivé par défaut ; « livré » part en notification, pas sur WhatsApp
        $this->assertSame(['push:colis_livre'], $this->merchantMessages());
        $push = OutboundMessage::where('channel', MessageChannel::Push->value)->sole();
        $this->assertSame(MessageStatus::Sent, $push->status);
        Http::assertSent(fn ($request) => $request->url() === self::ENDPOINT && $request->hasHeader('TTL', '86400'));

        // Le destinataire reste prévenu par WhatsApp
        $this->assertTrue(OutboundMessage::where('recipient_type', 'recipient')->where('channel', 'whatsapp')->exists());
    }

    public function test_notification_received_by_no_device_falls_back_to_whatsapp(): void
    {
        // Application désinstallée : le service push répond « n'existe plus »
        Http::fake([self::ENDPOINT => Http::response('', 410)]);
        $this->subscribeMerchantPhone();

        $this->deliver($this->createOrder());

        $this->assertSame(['push:colis_livre', 'whatsapp:colis_livre'], $this->merchantMessages());
        [$push, $whatsapp] = OutboundMessage::where('recipient_type', 'merchant')->orderBy('id')->get()->all();
        $this->assertSame(MessageStatus::Failed, $push->status);
        $this->assertSame($push->id, $whatsapp->fallback_for_id);
        $this->assertSame($push->payload, $whatsapp->payload);
        $this->assertSame(PhoneNumber::normalize($this->merchant->messagingPhone()), $whatsapp->to);
        $this->assertSame(0, PushSubscription::count());

        // L'appareil oublié, le message suivant part directement sur WhatsApp
        $this->deliver($this->createOrder());
        $this->assertSame('whatsapp:colis_livre', last($this->merchantMessages()));
    }

    public function test_a_passing_push_failure_is_tried_again_before_whatsapp(): void
    {
        Http::fake([self::ENDPOINT => Http::response('', 503)]);
        $this->subscribeMerchantPhone();
        $message = app(Messenger::class)->merchantMessage($this->merchant, 'order.delivered', WhatsAppTemplate::OrderDelivered, ['Boutique', 'JB-1', 'Awa', '5 000 F']);
        $message->forceFill(['status' => MessageStatus::Queued])->save();

        // Premier essai sans réponse du service push : la notification reste en attente d'un nouvel essai
        $job = (new SendOutboundMessage($message))->withFakeQueueInteractions();
        $job->handle(app(WhatsAppGateway::class), app(SmsGateway::class), app(Messenger::class), app(WebPush::class));
        $job->assertReleased();
        $this->assertSame(MessageStatus::Queued, $message->fresh()->status);

        // Dernier essai toujours sans réponse : WhatsApp prend le relais
        $job = (new SendOutboundMessage($message))->withFakeQueueInteractions();
        $job->job->attempts = SendOutboundMessage::PUSH_TRIES;
        $job->handle(app(WhatsAppGateway::class), app(SmsGateway::class), app(Messenger::class), app(WebPush::class));
        $job->assertNotReleased();
        $this->assertSame(MessageStatus::Failed, $message->fresh()->status);
        $this->assertSame(1, OutboundMessage::where('fallback_for_id', $message->id)->where('channel', MessageChannel::WhatsApp)->count());
    }

    public function test_a_suspended_account_no_longer_receives_the_merchant_notifications(): void
    {
        $this->subscribeMerchantPhone();
        $this->assertTrue($this->merchant->pushSubscriptions()->exists());

        $this->merchantUser->forceFill(['status' => 'suspended'])->save();
        $this->assertFalse($this->merchant->pushSubscriptions()->exists());
    }

    public function test_company_can_keep_whatsapp_for_merchants(): void
    {
        Http::fake();
        $this->subscribeMerchantPhone();

        $this->as($this->admin)->putJson('/api/v1/whatsapp/settings', ['merchant_push_first' => false])
            ->assertOk()
            ->assertJsonPath('data.merchant_push_first', false)
            ->assertJsonPath('data.merchants_with_push', 1);

        $this->deliver($this->createOrder());

        $this->assertSame(['whatsapp:colis_livre'], $this->merchantMessages());
        Http::assertNothingSent();
    }

    public function test_without_vapid_keys_whatsapp_is_used(): void
    {
        Http::fake();
        $this->subscribeMerchantPhone();
        config(['services.webpush.public_key' => null]);

        $this->deliver($this->createOrder());

        $this->assertSame(['whatsapp:colis_livre'], $this->merchantMessages());
    }

    public function test_merchant_settings_show_how_messages_arrive(): void
    {
        $url = "/api/v1/merchants/{$this->merchant->id}/notifications";

        $this->as($this->merchantUser)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.push_first', true)
            ->assertJsonPath('data.push_devices', 0)
            ->assertJsonPath('data.events.2.event', 'order.delivered')
            ->assertJsonPath('data.events.2.enabled', true);

        $this->subscribeMerchantPhone();
        $this->as($this->merchantUser)->putJson($url, ['events' => ['order.delivered' => false]])
            ->assertOk()
            ->assertJsonPath('data.push_devices', 1)
            ->assertJsonPath('data.events.2.enabled', false);

        // Événement coupé : ni notification ni WhatsApp
        Http::fake();
        $this->deliver($this->createOrder());
        $this->assertSame([], $this->merchantMessages());
    }

    public function test_scheduled_report_arrives_as_a_notification(): void
    {
        Http::fake([self::ENDPOINT => Http::response('', 201)]);
        $this->subscribeMerchantPhone();

        Carbon::setTestNow('2026-10-08 17:00:00');
        $this->as($this->merchantUser)->putJson("/api/v1/merchants/{$this->merchant->id}/notifications", [
            'reports' => ['daily' => ['active' => true, 'send_time' => '19:00']],
        ])->assertOk();
        $this->createOrder();

        Carbon::setTestNow('2026-10-08 19:02:00');
        $this->artisan('reports:send')->expectsOutput('1 rapport(s) envoyé(s).');

        $report = OutboundMessage::where('event', 'report.daily')->sole();
        $this->assertSame(MessageChannel::Push, $report->channel);
        $this->assertSame('/marchand/statistiques', Messenger::pushPayload($report)['url']);

        Carbon::setTestNow();
    }

    public function test_notification_text_is_short_and_opens_the_order(): void
    {
        $message = new OutboundMessage([
            'event' => 'order.incident',
            'order_id' => 42,
            'template_name' => 'incident_livraison',
            'body' => 'Bonjour Boutique Awa, incident sur le colis LIV-1 pour Koffi (Cocody) : livraison impossible, client injoignable. Choisissez la suite depuis votre espace : https://rapide.jibiat.com/marchand/courses/42 . Merci.',
        ]);

        $this->assertSame([
            'title' => '⚠️ Incident de livraison',
            'body' => 'Incident sur le colis LIV-1 pour Koffi (Cocody) : livraison impossible, client injoignable.',
            'url' => '/marchand/courses/42',
            'tag' => 'order.incident-42',
        ], Messenger::pushPayload($message));
    }
}
