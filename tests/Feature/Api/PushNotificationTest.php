<?php

namespace Tests\Feature\Api;

use App\Models\PushSubscription;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\OrderAlert;
use App\Services\Push\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Notifications push des livreurs (Web Push) : abonnement, chiffrement conforme RFC 8291,
 * signature VAPID, envoi sur nouvelle mission et consigne de l'agence.
 */
class PushNotificationTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    /** @var \OpenSSLAsymmetricKey clé privée du « téléphone » (pour déchiffrer dans le test) */
    private $deviceKey;

    private string $devicePublic;

    private string $authSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();

        $keys = WebPush::generateVapidKeys();
        config(['services.webpush.public_key' => $keys['public_key'], 'services.webpush.private_key' => $keys['private_key'], 'services.webpush.subject' => 'mailto:test@livraison.test']);

        // Clés de l'abonnement, comme les crée le navigateur du livreur
        $this->deviceKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($this->deviceKey)['ec'];
        $this->devicePublic = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
        $this->authSecret = random_bytes(16);
    }

    private function subscribe(): void
    {
        Sanctum::actingAs($this->courierB->user);
        $this->postJson('/api/v1/push/subscriptions', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => WebPush::b64($this->devicePublic), 'auth' => WebPush::b64($this->authSecret)],
        ])->assertCreated();
    }

    /**
     * Déchiffrement « aes128gcm » côté navigateur (RFC 8291), pour vérifier l'envoi.
     */
    private function decrypt(string $body): array
    {
        $salt = substr($body, 0, 16);
        $idLength = ord($body[20]);
        $serverPublic = substr($body, 21, $idLength);
        $cipher = substr($body, 21 + $idLength);

        $shared = openssl_pkey_derive(WebPush::publicKeyPem($serverPublic), $this->deviceKey, 32);
        $prkKey = hash_hmac('sha256', $shared, $this->authSecret, true);
        $ikm = substr(hash_hmac('sha256', "WebPush: info\0".$this->devicePublic.$serverPublic."\x01", $prkKey, true), 0, 32);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);

        $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));
        $this->assertNotFalse($plain, 'Le téléphone doit pouvoir déchiffrer le message');
        $this->assertSame("\x02", substr($plain, -1));

        return json_decode(substr($plain, 0, -1), true);
    }

    private function assertValidVapid(HttpRequest $request): void
    {
        [, $jwt, $key] = preg_split('/^vapid t=|, k=/', $request->header('Authorization')[0]);
        $this->assertSame(config('services.webpush.public_key'), $key);

        [$header, $claims, $signature] = explode('.', $jwt);
        $this->assertSame('https://fcm.googleapis.com', json_decode(WebPush::unb64($claims), true)['aud']);

        // Signature ES256 (r||s) vérifiée avec la clé publique VAPID
        $raw = WebPush::unb64($signature);
        $int = fn (string $v) => (ord(ltrim($v, "\0")[0] ?? "\0") > 0x7F ? "\0" : '').ltrim($v, "\0");
        [$r, $s] = [$int(substr($raw, 0, 32)), $int(substr($raw, 32))];
        $der = "\x30".chr(4 + strlen($r) + strlen($s))."\x02".chr(strlen($r)).$r."\x02".chr(strlen($s)).$s;
        $this->assertSame(1, openssl_verify("{$header}.{$claims}", $der, WebPush::publicKeyPem(WebPush::unb64($key)), OPENSSL_ALGO_SHA256));
    }

    public function test_courier_subscribes_and_receives_a_test_notification(): void
    {
        Http::fake([self::ENDPOINT => Http::response('', 201)]);
        Sanctum::actingAs($this->courierB->user);

        $this->getJson('/api/v1/push')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.devices', 0);
        $this->subscribe();
        $this->getJson('/api/v1/push')->assertJsonPath('data.devices', 1);

        $this->postJson('/api/v1/push/test')->assertOk()->assertJsonPath('data.devices', 1);

        Http::assertSent(function (HttpRequest $request) {
            $this->assertSame('aes128gcm', $request->header('Content-Encoding')[0]);
            $this->assertValidVapid($request);
            $payload = $this->decrypt($request->body());

            return $request->url() === self::ENDPOINT && $payload['title'] === '🔔 Notifications activées' && $payload['url'] === '/livreur';
        });

        // Un abonnement invalide est refusé ; se désabonner supprime l'appareil
        $this->postJson('/api/v1/push/subscriptions', ['endpoint' => 'https://x.test/1', 'keys' => ['p256dh' => 'abc', 'auth' => 'def']])->assertStatus(422);
        $this->deleteJson('/api/v1/push/subscriptions', ['endpoint' => self::ENDPOINT])->assertNoContent();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_new_mission_and_dispatch_message_are_pushed_to_the_courier(): void
    {
        $this->subscribe();
        Http::fake([self::ENDPOINT => Http::response('', 201)]);

        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();
        $assignmentId = $order->assignments()->latest('id')->value('id');

        $this->postJson("/api/v1/orders/{$order->id}/courier-messages", ['body' => 'Appelle le client en arrivant'])->assertCreated();

        $payloads = collect(Http::recorded())->map(fn ($pair) => $this->decrypt($pair[0]->body()));
        $this->assertCount(2, $payloads);
        $this->assertSame('Nouvelle mission : Livraison', $payloads[0]['title']);
        $this->assertSame("/livreur/missions/{$assignmentId}", $payloads[0]['url']);
        $this->assertSame('Appelle le client en arrivant', $payloads[1]['body']);
        $this->assertTrue($payloads[1]['urgent']);
    }

    public function test_a_shared_phone_keeps_the_notifications_of_each_account(): void
    {
        $this->subscribe();
        // Le même téléphone, ouvert ensuite avec un autre compte : le premier garde ses notifications
        $other = $this->courierA->user;
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/push/subscriptions', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => WebPush::b64($this->devicePublic), 'auth' => WebPush::b64($this->authSecret)],
        ])->assertCreated();
        $this->assertEqualsCanonicalizing([$this->courierB->user->id, $other->id], PushSubscription::pluck('user_id')->all());

        // Déconnexion du second compte : seul son abonnement est retiré
        $this->deleteJson('/api/v1/push/subscriptions', ['endpoint' => self::ENDPOINT])->assertNoContent();
        $this->assertSame([$this->courierB->user->id], PushSubscription::pluck('user_id')->all());
    }

    public function test_a_passing_push_failure_is_tried_again(): void
    {
        $this->subscribe();
        // Service push en panne passagère, puis rétabli
        Http::fake([self::ENDPOINT => Http::sequence()->push('', 503)->push('', 201)]);

        Notification::sendNow($this->courierB->user->fresh(), new OrderAlert($this->createOrder(), 'new_mission', 'Nouvelle mission', 'Corps'));

        Http::assertSentCount(2);
        $this->assertNotNull(PushSubscription::first()->last_used_at);
    }

    public function test_push_is_only_for_couriers_with_a_device_and_expired_devices_are_forgotten(): void
    {
        $order = $this->createOrder();

        // Pas d'appareil : pas de canal push
        $alert = new OrderAlert($order, 'new_mission', 'Titre', 'Corps');
        $this->assertNotContains(WebPushChannel::class, $alert->via($this->courierB->user));

        $this->subscribe();
        $this->assertContains(WebPushChannel::class, $alert->via($this->courierB->user->fresh()));
        // Les autres alertes ne partent pas en push
        $this->assertNotContains(WebPushChannel::class, (new OrderAlert($order, 'note', 'T', 'C'))->via($this->courierB->user->fresh()));

        // Serveur sans clés VAPID : rien
        config(['services.webpush.private_key' => null]);
        $this->assertNotContains(WebPushChannel::class, $alert->via($this->courierB->user->fresh()));
        config(['services.webpush.private_key' => WebPush::generateVapidKeys()['private_key']]);

        // Abonnement expiré (410) : supprimé
        Http::fake([self::ENDPOINT => Http::response('', 410)]);
        Notification::sendNow($this->courierB->user->fresh(), $alert);
        $this->assertSame(0, PushSubscription::count());
    }
}
