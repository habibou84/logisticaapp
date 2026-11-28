<?php

namespace Tests\Feature\Api;

use App\Enums\MessageStatus;
use App\Models\Company;
use App\Models\OutboundMessage;
use App\Models\WhatsAppQrSession;
use App\Notifications\AlertWhatsAppDisconnected;
use App\Services\Messaging\AlertWhatsApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * WhatsApp des alertes : numéro relié en scannant un QR code (conteneur WAHA), envois espacés
 * et plafonnés, déconnexion signalée aux administrateurs.
 */
class AlertWhatsAppTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const BASE = 'http://whatsapp-qr:3000';

    protected function setUp(): void
    {
        parent::setUp();
        config(['messaging.qr.url' => self::BASE, 'messaging.qr.api_key' => 'cle-secrete', 'messaging.whatsapp.driver' => 'auto']);
        $this->buildWorld();
    }

    /** État de la session chez WAHA ; null : service injoignable */
    private ?string $status = 'STOPPED';

    /** Réponse de l'envoi d'un message */
    private int $sendStatus = 201;

    private int $sent = 0;

    /** Session WAHA dans l'état donné (un seul faux, relu à chaque appel) */
    private function fakeWaha(string $status): void
    {
        $this->status = $status;
        Http::fake(function (HttpRequest $r) {
            if ($this->status === null) {
                throw new ConnectionException('Connexion refusée');
            }
            $path = substr($r->url(), strlen(self::BASE));

            return match (true) {
                $path === '/api/sessions/default' && $r->method() === 'DELETE' => Http::response(null, 204),
                $path === '/api/sessions/default' => Http::response(['name' => 'default', 'status' => $this->status,
                    'me' => $this->status === 'WORKING' ? ['id' => '2250700000001@c.us', 'pushName' => 'Alertes Logistica'] : null]),
                $path === '/api/sessions/default/start' => Http::response(['name' => 'default', 'status' => 'STARTING']),
                $path === '/api/default/auth/qr' => Http::response(['mimetype' => 'image/png', 'data' => 'UVJDT0RF']),
                $path === '/api/sendText' && $this->sendStatus === 201 => Http::response(['id' => 'true_'.$r['chatId'].'_'.(++$this->sent)], 201),
                $path === '/api/sendText' => Http::response(['message' => 'Session status is not as expected', 'status' => 'SCAN_QR_CODE'], $this->sendStatus),
                default => Http::response(null, 404),
            };
        });
    }

    private function connected(?Company $company = null): WhatsAppQrSession
    {
        return WhatsAppQrSession::create([
            'company_id' => ($company ?? $this->company)->id, 'status' => 'connected',
            'phone' => '+2250700000001', 'connected_at' => now()->subDay(),
        ]);
    }

    public function test_admin_links_the_number_by_scanning_the_qr_code(): void
    {
        Sanctum::actingAs($this->admin);

        // Pas encore relié
        $this->fakeWaha('STOPPED');
        $this->getJson('/api/v1/alert-whatsapp')
            ->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.status', 'disconnected');

        // « Relier par QR code » : session démarrée, QR code affiché
        $this->status = 'SCAN_QR_CODE';
        $this->postJson('/api/v1/alert-whatsapp/connect')
            ->assertOk()->assertJsonPath('data.status', 'scanning')->assertJsonPath('data.qr', 'UVJDT0RF');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::BASE.'/api/sessions/default/start' && $r->hasHeader('X-Api-Key', 'cle-secrete'));

        // Téléphone scanné : relié, numéro et nom affichés
        $this->status = 'WORKING';
        $this->getJson('/api/v1/alert-whatsapp')
            ->assertJsonPath('data.status', 'connected')->assertJsonPath('data.phone', '+2250700000001')
            ->assertJsonPath('data.name', 'Alertes Logistica')->assertJsonPath('data.qr', null);
        $this->assertTrue(app(AlertWhatsApp::class)->isConnected($this->company->id));

        // Essai envoyé au numéro de l'administrateur, par le WhatsApp des alertes
        $this->postJson('/api/v1/alert-whatsapp/test')->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::BASE.'/api/sendText'
            && $r['chatId'] === ltrim($this->admin->phone, '+').'@c.us' && $r['session'] === 'default');
        $message = OutboundMessage::query()->where('via', 'qr')->sole();
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame('qr-true_'.ltrim($this->admin->phone, '+').'@c.us_1', $message->provider_message_id);

        // Délier : session supprimée chez WAHA, alertes de nouveau par le moyen habituel
        $this->status = 'STOPPED';
        $this->deleteJson('/api/v1/alert-whatsapp')->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && $r->url() === self::BASE.'/api/sessions/default');
        $this->assertFalse(app(AlertWhatsApp::class)->isConnected($this->company->id));
    }

    public function test_reserved_to_settings_managers_and_hidden_without_the_container(): void
    {
        $this->fakeWaha('STOPPED');
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/alert-whatsapp')->assertForbidden();
        Sanctum::actingAs($this->merchantUser);
        $this->postJson('/api/v1/alert-whatsapp/connect')->assertForbidden();

        // Pas de clé : indisponible, aucun appel
        config(['messaging.qr.api_key' => null]);
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/alert-whatsapp')->assertOk()->assertJsonPath('data.available', false);
        $this->postJson('/api/v1/alert-whatsapp/connect')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_alerts_are_spaced_capped_and_left_to_the_usual_channel_when_not_linked(): void
    {
        $this->fakeWaha('WORKING');
        $alerts = app(AlertWhatsApp::class);

        // Pas relié : null, l'appelant garde son moyen habituel
        $this->assertNull($alerts->send($this->company->id, ['0700000002'], 'Zone Cocody : 62 % de réussite'));
        Http::assertNothingSent();

        $this->connected();
        config(['messaging.qr.max_per_hour' => 2]);
        $messages = $alerts->send($this->company->id, ['0700000002', '+2250700000002', '0700000003', '0700000004', null], 'Zone Cocody : 62 % de réussite');

        // Numéros en double ou vides ignorés ; au-delà du plafond horaire, pas d'envoi (et pas de SMS)
        $this->assertCount(3, $messages);
        $this->assertSame([MessageStatus::Sent, MessageStatus::Sent, MessageStatus::Failed], array_map(fn ($m) => $m->fresh()->status, $messages));
        Http::assertSentCount(2);
        $this->assertSame(0, OutboundMessage::query()->where('channel', 'sms')->count());
    }

    public function test_forced_simulation_keeps_alerts_simulated_but_sends_the_test(): void
    {
        config(['messaging.whatsapp.driver' => 'log']);
        $this->fakeWaha('WORKING');
        $this->connected();
        $alerts = app(AlertWhatsApp::class);

        $alert = $alerts->send($this->company->id, ['0700000002'], 'Zone Cocody : 62 % de réussite')[0]->fresh();
        $this->assertSame(MessageStatus::Sent, $alert->status);
        $this->assertStringStartsWith('log-', $alert->provider_message_id);
        Http::assertNothingSent();

        $test = $alerts->test($this->company->id, $this->admin->phone)->fresh();
        $this->assertStringStartsWith('qr-', $test->provider_message_id);
        Http::assertSentCount(1);
    }

    public function test_send_failure_when_the_session_dropped_does_not_fall_back_to_sms(): void
    {
        $this->company->update(['sms_fallback' => true]);
        $this->connected();
        $this->fakeWaha('SCAN_QR_CODE');
        $this->sendStatus = 422;

        $message = app(AlertWhatsApp::class)->send($this->company->id, ['0700000002'], 'Alerte')[0]->fresh();

        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertStringContainsString('scannez à nouveau le QR code', $message->error);
        $this->assertSame(0, OutboundMessage::query()->where('channel', 'sms')->count());
    }

    public function test_admins_are_warned_once_when_the_session_stays_disconnected(): void
    {
        Notification::fake();
        $this->connected();

        // Session perdue (téléphone délié) : relance tentée, pas encore d'alerte
        $this->fakeWaha('FAILED');
        $this->artisan('whatsapp-qr:watch')->assertSuccessful();
        $session = WhatsAppQrSession::query()->sole();
        $this->assertSame('disconnected', $session->status);
        $this->assertNotNull($session->disconnected_at);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::BASE.'/api/sessions/default/start');
        Notification::assertNothingSent();

        // Toujours déconnecté 10 minutes plus tard : administrateurs prévenus, une seule fois
        $this->travel(11)->minutes();
        $this->artisan('whatsapp-qr:watch');
        $this->artisan('whatsapp-qr:watch');
        Notification::assertSentToTimes($this->admin, AlertWhatsAppDisconnected::class, 1);
        Notification::assertNotSentTo($this->dispatcher, AlertWhatsAppDisconnected::class);

        // Revenu : l'alerte pourra repartir à la prochaine coupure
        $this->status = 'WORKING';
        $this->artisan('whatsapp-qr:watch');
        $session->refresh();
        $this->assertSame('connected', $session->status);
        $this->assertNull($session->warned_at);
        $this->assertNull($session->disconnected_at);
    }

    public function test_short_restart_of_the_container_is_not_reported(): void
    {
        Notification::fake();
        $this->connected();

        // Service en cours de redémarrage : la session reprend d'elle-même
        $this->fakeWaha('STARTING');
        $this->artisan('whatsapp-qr:watch');
        $this->assertSame('connected', WhatsAppQrSession::query()->sole()->status);

        // Service injoignable quelques minutes puis revenu : pas d'alerte
        $this->status = null;
        $this->artisan('whatsapp-qr:watch');
        $this->assertSame('disconnected', WhatsAppQrSession::query()->sole()->status);
        $this->travel(5)->minutes();
        $this->status = 'WORKING';
        $this->artisan('whatsapp-qr:watch');
        $this->assertSame('connected', WhatsAppQrSession::query()->sole()->status);
        Notification::assertNothingSent();
    }
}
