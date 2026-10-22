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
        config(['platform.domain' => 'preprod.jibiat.test', 'platform.scheme' => 'http', 'platform.preprod_access_code' => 'mangue-2026']);
        $this->company->update(['slug' => 'express-ds']);
    }

    public function test_every_address_asks_for_the_access_code(): void
    {
        $this->get('http://preprod.jibiat.test/')->assertUnauthorized()->assertSee('Saisissez le code d\'accès', false)
            ->assertHeader('Cache-Control')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('http://admin.preprod.jibiat.test/console')->assertUnauthorized();
        $this->get('http://express-ds.preprod.jibiat.test/admin')->assertUnauthorized()->assertSee('Préproduction');
        $this->getJson('http://express-ds.preprod.jibiat.test/api/v1/auth/me')->assertForbidden()
            ->assertJson(['message' => 'Préproduction : saisissez le code d\'accès.']);
    }

    public function test_the_right_code_opens_every_address_for_thirty_days(): void
    {
        $this->post('http://express-ds.preprod.jibiat.test/'.PreprodGate::PATH, ['code' => 'faux', 'retour' => '/admin'])
            ->assertForbidden()->assertSee('Code incorrect.');

        $response = $this->post('http://express-ds.preprod.jibiat.test/'.PreprodGate::PATH, ['code' => 'mangue-2026', 'retour' => '/admin'])
            ->assertRedirect('/admin');
        $cookie = $response->getCookie(PreprodGate::COOKIE, false);
        $this->assertSame('.preprod.jibiat.test', $cookie->getDomain());
        $this->assertTrue($cookie->getExpiresTime() > now()->addDays(29)->getTimestamp());

        // Même code pour la plateforme, la console et toutes les entreprises de test
        $this->withUnencryptedCookie(PreprodGate::COOKIE, $cookie->getValue())->get('http://express-ds.preprod.jibiat.test/admin')->assertOk();
        $this->withUnencryptedCookie(PreprodGate::COOKIE, $cookie->getValue())->get('http://preprod.jibiat.test/')->assertOk();
        $this->withUnencryptedCookie(PreprodGate::COOKIE, 'invente')->get('http://preprod.jibiat.test/')->assertUnauthorized();
    }

    public function test_the_return_address_stays_on_the_site(): void
    {
        $this->post('http://preprod.jibiat.test/'.PreprodGate::PATH, ['code' => 'mangue-2026', 'retour' => '//pirate.example/x'])->assertRedirect('/');
    }

    public function test_webhooks_and_health_check_stay_open(): void
    {
        $this->get('http://preprod.jibiat.test/up')->assertOk();
        // Webhook Meta : atteint (refusé faute de signature valide), pas la page du code
        $this->postJson('http://preprod.jibiat.test/api/webhooks/whatsapp', [])->assertDontSee('Saisissez le code', false);
    }

    public function test_apps_stay_installable_behind_the_code(): void
    {
        // Le navigateur télécharge le manifeste sans cookie : il doit le recevoir pour proposer l'installation
        $this->get('http://express-ds.preprod.jibiat.test/manifest/livreur.webmanifest')
            ->assertOk()->assertHeader('Content-Type', 'application/manifest+json');
        // Les pages restent protégées
        $this->get('http://express-ds.preprod.jibiat.test/livreur')->assertStatus(401);
    }

    public function test_preproduction_is_never_indexed(): void
    {
        config(['platform.preprod_access_code' => '', 'platform.indexable' => false]);

        $this->get('http://preprod.jibiat.test/robots.txt')->assertOk()->assertSee('Disallow: /', false);
        $this->get('http://express-ds.preprod.jibiat.test/admin')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_production_is_open_and_indexed(): void
    {
        config(['platform.domain' => 'jibiat.test', 'platform.indexable' => true]);
        $this->app->detectEnvironment(fn () => 'production');

        // Le code est ignoré en production
        $this->get('http://express-ds.jibiat.test/admin')->assertOk()->assertHeaderMissing('X-Robots-Tag');
        $robots = $this->get('http://jibiat.test/robots.txt')->assertOk()->getContent();
        $this->assertSame("User-agent: *\nDisallow:\n", $robots);
    }
}
