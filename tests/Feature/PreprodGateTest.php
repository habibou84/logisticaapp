<?php

namespace Tests\Feature;

use App\Http\Middleware\PreprodGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Préproduction (preprod.jibiat.com) : fermée au public par un code d'accès, jamais indexée.
 */
class PreprodGateTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        // Pages de l'application rendues sans l'interface compilée (absente en intégration continue)
        $this->withoutVite();
        config(['platform.preprod_access_code' => 'mangue-2026']);
    }

    public function test_every_page_asks_for_the_access_code(): void
    {
        $this->get('/')->assertUnauthorized()->assertSee('Saisissez le code d\'accès', false)
            ->assertHeader('Cache-Control')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/admin')->assertUnauthorized()->assertSee('Préproduction');
        $this->getJson('/api/v1/auth/me')->assertForbidden()
            ->assertJson(['message' => 'Préproduction : saisissez le code d\'accès.']);
    }

    public function test_the_right_code_opens_the_site_for_thirty_days(): void
    {
        $this->post('/'.PreprodGate::PATH, ['code' => 'faux', 'retour' => '/admin'])
            ->assertForbidden()->assertSee('Code incorrect.');

        $response = $this->post('/'.PreprodGate::PATH, ['code' => 'mangue-2026', 'retour' => '/admin'])
            ->assertRedirect('/admin');
        $cookie = $response->getCookie(PreprodGate::COOKIE, false);
        $this->assertNull($cookie->getDomain());
        $this->assertTrue($cookie->getExpiresTime() > now()->addDays(29)->getTimestamp());

        $this->withUnencryptedCookie(PreprodGate::COOKIE, $cookie->getValue())->get('/admin')->assertOk();
        $this->withUnencryptedCookie(PreprodGate::COOKIE, $cookie->getValue())->get('/')->assertOk();
        $this->withUnencryptedCookie(PreprodGate::COOKIE, 'invente')->get('/')->assertUnauthorized();
    }

    public function test_the_return_address_stays_on_the_site(): void
    {
        $this->post('/'.PreprodGate::PATH, ['code' => 'mangue-2026', 'retour' => '//pirate.example/x'])->assertRedirect('/');
    }

    public function test_webhooks_and_health_check_stay_open(): void
    {
        $this->get('/up')->assertOk();
        // Webhook Meta : atteint (refusé faute de signature valide), pas la page du code
        $this->postJson('/api/webhooks/whatsapp', [])->assertDontSee('Saisissez le code', false);
    }

    public function test_apps_stay_installable_behind_the_code(): void
    {
        // Le navigateur télécharge le manifeste sans cookie : il doit le recevoir pour proposer l'installation
        $this->get('/manifest/livreur.webmanifest')
            ->assertOk()->assertHeader('Content-Type', 'application/manifest+json');
        // Les pages restent protégées
        $this->get('/livreur')->assertStatus(401);
    }

    public function test_preproduction_is_never_indexed(): void
    {
        config(['platform.preprod_access_code' => '', 'platform.indexable' => false]);

        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /', false);
        $this->get('/admin')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_production_is_open_and_indexed(): void
    {
        config(['platform.indexable' => true]);
        $this->app->detectEnvironment(fn () => 'production');

        // Le code est ignoré en production
        $this->get('/admin')->assertOk()->assertHeaderMissing('X-Robots-Tag');
        $robots = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertSame("User-agent: *\nDisallow:\n", $robots);
    }
}
