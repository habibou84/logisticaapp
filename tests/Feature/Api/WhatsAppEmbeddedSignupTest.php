<?php

namespace Tests\Feature\Api;

use App\Enums\WhatsAppTemplate;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Bouton « Connecter WhatsApp » (inscription intégrée de Meta) : le code d'autorisation est
 * échangé contre un jeton, le numéro est activé, vérifié, abonné et ses modèles soumis.
 */
class WhatsAppEmbeddedSignupTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        config([
            'messaging.whatsapp.driver' => 'auto',
            'messaging.whatsapp.app_id' => '1111',
            'messaging.whatsapp.signup_config_id' => '2222',
            'messaging.whatsapp.app_secret' => 'app-secret',
        ]);
    }

    private function fakeMeta(): void
    {
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'jeton-entreprise', 'token_type' => 'bearer']),
            '*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'expires_at' => now()->addDays(60)->timestamp]]),
            '*/777/assigned_users' => Http::response(['success' => true]),
            '*/555/register' => Http::response(['success' => true]),
            '*/777/subscribed_apps' => Http::response(['success' => true]),
            '*/777/message_templates' => Http::response(['id' => '1', 'status' => 'PENDING']),
            '*/555*' => Http::response(['display_phone_number' => '+225 07 00 00 00 01', 'verified_name' => 'Express DS', 'quality_rating' => 'GREEN']),
        ]);
    }

    /** Ticket remis par l'espace de l'entreprise (utilisateur connecté) */
    private function ticket(): string
    {
        Sanctum::actingAs($this->admin);
        $url = $this->postJson('/api/v1/whatsapp/signup-ticket')->assertOk()->json('data.url');
        $this->assertStringContainsString('/connecter-whatsapp#', $url);

        return substr($url, strpos($url, '#') + 1);
    }

    /** Page de la console : fin de la fenêtre de Meta, sans session (le ticket suffit) */
    private function signup(array $data = [], ?string $ticket = null)
    {
        $ticket ??= $this->ticket();
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/whatsapp-signup/complete', ['ticket' => $ticket, 'code' => 'code-meta', 'phone_number_id' => '555', 'waba_id' => '777', ...$data]);
    }

    public function test_one_click_connection_activates_the_number(): void
    {
        $this->fakeMeta();
        Sanctum::actingAs($this->admin);

        // Identifiants publics de l'application pour le bouton, jamais la clé secrète
        $this->getJson('/api/v1/whatsapp/settings')
            ->assertJsonPath('data.embedded_signup.app_id', '1111')
            ->assertJsonPath('data.embedded_signup.config_id', '2222')
            ->assertJsonMissing(['app-secret']);

        // Page de la console : entreprise et identifiants publics, à partir du seul ticket
        $ticket = $this->ticket();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/whatsapp-signup/session', ['ticket' => $ticket])->assertOk()
            ->assertJsonPath('data.company', $this->company->name)
            ->assertJsonPath('data.origin', rtrim(config('app.url'), '/'))
            ->assertJsonPath('data.embedded_signup.config_id', '2222');

        $this->signup(ticket: $ticket)->assertOk()
            ->assertJsonPath('data.verified_name', 'Express DS')
            ->assertJsonPath('data.warning', null);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/whatsapp/settings')
            ->assertJsonPath('data.mode', 'live')
            ->assertJsonPath('data.account.status', 'connected')
            ->assertJsonPath('data.account.connected_via', 'embedded_signup');

        $account = WhatsAppAccount::forCompanyId($this->company->id);
        $this->assertSame('jeton-entreprise', $account->access_token);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $account->registration_pin);
        $this->assertNotNull($account->webhook_subscribed_at);

        // Code échangé avec la clé secrète, numéro activé avec son PIN, modèles soumis avec le nouveau jeton
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/oauth/access_token')
            && $r['client_id'] === '1111' && $r['client_secret'] === 'app-secret' && $r['code'] === 'code-meta');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/555/register')
            && $r['pin'] === $account->registration_pin && $r->hasHeader('Authorization', 'Bearer jeton-entreprise'));
        Http::assertSentCount(5 + count(WhatsAppTemplate::cases()));

        // Jeton de 60 jours (configuration Meta) : date d'expiration affichée, à renouveler
        $this->assertFalse($account->platform_token);
        $this->assertTrue($account->token_expires_at->between(now()->addDays(59), now()->addDays(61)));
        Sanctum::actingAs($this->admin);
        $this->assertNotNull($this->getJson('/api/v1/whatsapp/settings')->json('data.account.token_expires_at'));
        $this->assertSame(count(WhatsAppTemplate::cases()), $account->templates()->where('status', 'pending')->count());

        // Reconnexion du même numéro : même PIN
        $pin = $account->registration_pin;
        $this->signup(['code' => 'autre-code'])->assertOk();
        $this->assertSame($pin, $account->fresh()->registration_pin);
    }

    public function test_refused_authorization_changes_nothing(): void
    {
        Http::fake(['*/oauth/access_token*' => Http::response(['error' => ['message' => 'Invalid verification code format.', 'code' => 100]], 400)]);

        $this->signup()->assertStatus(422)->assertJsonPath('message', 'Autorisation de Meta refusée ou expirée : recommencez la connexion. (Meta : Invalid verification code format.)');
        $this->assertNull(WhatsAppAccount::forCompanyId($this->company->id));
    }

    public function test_tickets_are_short_lived_and_tied_to_an_authorised_user(): void
    {
        Http::fake();
        $ticket = $this->ticket();

        // Ticket falsifié
        $this->postJson('/api/v1/whatsapp-signup/session', ['ticket' => 'faux'])->assertForbidden();

        // Utilisateur qui n'a plus le droit de régler l'entreprise
        $this->admin->syncRoles(['dispatcher']);
        $this->postJson('/api/v1/whatsapp-signup/session', ['ticket' => $ticket])->assertForbidden();
        $this->admin->syncRoles(['admin']);
        $this->postJson('/api/v1/whatsapp-signup/session', ['ticket' => $ticket])->assertOk();

        // Expiré au bout de 20 minutes
        $this->travel(21)->minutes();
        $this->signup(ticket: $ticket)->assertForbidden()->assertJsonPath('message', 'Lien de connexion expiré : recommencez depuis la page WhatsApp de votre espace.');
        Http::assertNothingSent();
    }

    public function test_meta_window_always_opens_from_the_platform_address(): void
    {
        $this->withoutVite();
        config(['platform.domain' => 'jibiat.test', 'platform.scheme' => 'https']);
        $this->company->update(['slug' => 'express-ds']);
        Sanctum::actingAs($this->admin);

        // Une seule adresse à déclarer chez Facebook : jibiat.com, quelle que soit l'entreprise
        $url = $this->postJson('http://express-ds.jibiat.test/api/v1/whatsapp/signup-ticket')->assertOk()->json('data.url');
        $this->assertStringStartsWith('https://jibiat.test/connecter-whatsapp#', $url);

        // La page s'ouvre sur l'adresse de la plateforme, sans invitation à installer une application
        $this->get('http://jibiat.test/connecter-whatsapp')->assertOk()
            ->assertSee('<div id="app">', false)
            ->assertDontSee('rel="manifest"', false);

        $this->app['auth']->forgetGuards();
        $this->postJson('http://jibiat.test/api/v1/whatsapp-signup/session', ['ticket' => substr($url, strpos($url, '#') + 1)])
            ->assertOk()->assertJsonPath('data.origin', 'http://express-ds.jibiat.test');

        // Le reste de l'adresse de la plateforme ne change pas : site de présentation, pas d'API
        $this->getJson('http://jibiat.test/api/v1/whatsapp/settings')->assertNotFound();
    }

    public function test_button_requires_server_configuration_and_settings_permission(): void
    {
        Http::fake();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson('/api/v1/whatsapp/signup-ticket')->assertForbidden();

        config(['messaging.whatsapp.signup_config_id' => null]);
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/whatsapp/settings')->assertJsonPath('data.embedded_signup', null);
        $this->postJson('/api/v1/whatsapp/signup-ticket')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_platform_system_user_takes_over_the_expiring_token(): void
    {
        config(['messaging.whatsapp.system_user_id' => '9999', 'messaging.whatsapp.system_user_token' => 'jeton-plateforme']);
        $this->fakeMeta();

        $this->signup()->assertOk();

        // Utilisateur système de Jibiat ajouté au compte WhatsApp de l'entreprise, avec le jeton permanent
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/777/assigned_users')
            && $r['user'] === '9999' && $r['tasks'] === ['MANAGE'] && $r->hasHeader('Authorization', 'Bearer jeton-plateforme'));
        // Les appels suivants (activation, modèles) utilisent ce jeton permanent
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/555/register') && $r->hasHeader('Authorization', 'Bearer jeton-plateforme'));

        $account = WhatsAppAccount::forCompanyId($this->company->id);
        $this->assertTrue($account->platform_token);
        $this->assertSame('jeton-plateforme', $account->apiToken());
        $this->assertSame('jeton-entreprise', $account->access_token);

        // Plus de date limite à afficher
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/whatsapp/settings')->assertJsonPath('data.account.token_expires_at', null);

        // Jeton saisi à la main : il remplace celui de la plateforme
        $this->putJson('/api/v1/whatsapp/settings', ['account' => ['access_token' => 'jeton-manuel']])->assertOk();
        $this->assertSame('jeton-manuel', $account->fresh()->apiToken());
    }

    public function test_expiring_access_is_renewed_by_the_platform_or_announced(): void
    {
        $this->fakeMeta();
        $this->signup()->assertOk();
        $account = WhatsAppAccount::forCompanyId($this->company->id);
        $this->assertFalse($account->platform_token);

        // Loin de l'échéance : rien
        $this->artisan('whatsapp:tokens')->assertSuccessful();
        $this->assertSame(0, $this->admin->notifications()->count());

        // 7 jours avant : une alerte (une seule) aux administrateurs
        $this->travel(54)->days();
        $this->artisan('whatsapp:tokens')->assertSuccessful();
        $this->artisan('whatsapp:tokens')->assertSuccessful();
        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertSame('whatsapp_access', $this->admin->notifications()->first()->data['kind']);
        $this->assertSame('connected', $account->fresh()->status);

        // Échéance passée : numéro déconnecté (plus d'envois réels), administrateurs prévenus
        $this->travel(7)->days();
        $this->artisan('whatsapp:tokens')->assertSuccessful();
        $this->assertSame('error', $account->fresh()->status);
        $this->assertFalse($account->fresh()->isLive());
        $this->assertSame(2, $this->admin->notifications()->count());
    }

    public function test_platform_takes_over_existing_connections_once_configured(): void
    {
        $this->fakeMeta();
        $this->signup()->assertOk();

        config(['messaging.whatsapp.system_user_id' => '9999', 'messaging.whatsapp.system_user_token' => 'jeton-plateforme']);
        $this->artisan('whatsapp:tokens')->assertSuccessful();

        $account = WhatsAppAccount::forCompanyId($this->company->id);
        $this->assertTrue($account->platform_token);

        // Plus d'alerte ni de déconnexion à l'échéance du jeton de l'entreprise
        $this->travel(61)->days();
        $this->artisan('whatsapp:tokens')->assertSuccessful();
        $this->assertSame('connected', $account->fresh()->status);
        $this->assertSame(0, $this->admin->notifications()->count());
    }
}
