<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Plan;
use App\Notifications\CustomDomainActivated;
use App\Services\Platform\CustomDomains;
use App\Support\Dns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Domaine personnalisé d'une entreprise : choix, vérification (DNS et HTTPS),
 * reconnaissance de l'adresse, liens envoyés, module de la formule.
 */
class CustomDomainTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'platform.domain' => 'jibiat.test',
            'platform.custom_domains.target' => 'clients.jibiat.test',
        ]);
        Notification::fake();
        $this->buildWorld();
        $this->company->update(['slug' => 'rapide']);
        // DNS du client : le CNAME est en place
        $this->app->instance(Dns::class, new class extends Dns
        {
            public function records(string $host): array
            {
                return ['cname' => ['clients.jibiat.test'], 'a' => []];
            }
        });
    }

    private function api(string $path): string
    {
        return 'http://rapide.jibiat.test/api/v1'.$path;
    }

    public function test_admin_sets_and_verifies_a_custom_domain(): void
    {
        Http::fake(['https://livraison.rapide.ci/.well-known/domain-check' => Http::response(['slug' => 'rapide'])]);
        Sanctum::actingAs($this->admin);

        $this->getJson($this->api('/company/domain'))->assertOk()
            ->assertJsonPath('data.platform_url', 'https://rapide.jibiat.test')
            ->assertJsonPath('data.custom_domain', null)
            ->assertJsonPath('data.included', true);

        $data = $this->putJson($this->api('/company/domain'), ['domain' => 'https://Livraison.Rapide.ci/'])->assertOk()->json('data');
        $this->assertSame(['livraison.rapide.ci', 'active', true, true], [$data['custom_domain'], $data['status'], $data['dns']['ok'], $data['https']['ok']]);
        $this->assertSame(['type' => 'CNAME', 'name' => 'livraison.rapide.ci', 'value' => 'clients.jibiat.test'], $data['dns_record']);
        Notification::assertSentTo($this->admin, CustomDomainActivated::class);

        // Les liens envoyés utilisent désormais le domaine de l'entreprise
        $this->assertSame('https://livraison.rapide.ci/suivi/X', $this->company->fresh()->url('/suivi/X'));
    }

    public function test_domain_stays_pending_until_https_answers_for_this_company(): void
    {
        // Le serveur ne reconnaît pas encore le domaine, puis le reconnaît
        Http::fake(['https://livraison.rapide.ci/*' => Http::sequence()->push(['slug' => 'autre'])->push(['slug' => 'rapide'])]);
        Sanctum::actingAs($this->admin);

        $this->putJson($this->api('/company/domain'), ['domain' => 'livraison.rapide.ci'])->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.error', 'Le domaine répond, mais pas avec votre espace : le serveur ne le reconnaît pas encore.');
        $this->assertSame('https://rapide.jibiat.test', $this->company->fresh()->url());

        // La vérification en arrière-plan l'active dès qu'il répond
        $this->artisan('domains:check')->assertSuccessful();
        $this->assertSame('active', $this->company->fresh()->custom_domain_status);
    }

    public function test_domain_rules(): void
    {
        Http::fake(['*' => Http::response(['slug' => null])]);
        Company::factory()->create(['slug' => 'eclair', 'custom_domain' => 'pris.ci']);
        Sanctum::actingAs($this->admin);

        $this->putJson($this->api('/company/domain'), ['domain' => 'pas un domaine'])->assertJsonValidationErrors('domain');
        $this->putJson($this->api('/company/domain'), ['domain' => 'autre.jibiat.test'])->assertJsonValidationErrors('domain');
        $this->putJson($this->api('/company/domain'), ['domain' => 'pris.ci'])->assertJsonValidationErrors('domain');

        // Réservé aux administrateurs (paramètres)
        Sanctum::actingAs($this->dispatcher);
        $this->putJson($this->api('/company/domain'), ['domain' => 'livraison.rapide.ci'])->assertForbidden();
    }

    public function test_custom_domain_is_recognized_as_the_company_address(): void
    {
        $this->company->forceFill(['custom_domain' => 'livraison.rapide.ci', 'custom_domain_status' => 'pending'])->save();

        $this->getJson('http://livraison.rapide.ci'.CustomDomains::CHECK_PATH)->assertOk()->assertJsonPath('slug', 'rapide');
        $this->getJson('http://www.livraison.rapide.ci/api/v1/branding')->assertOk()->assertJsonPath('data.name', $this->company->name);
        // Domaine inconnu
        $this->getJson('http://inconnu.ci/api/v1/branding')->assertNotFound();
        $this->getJson('http://inconnu.ci'.CustomDomains::CHECK_PATH)->assertNotFound();
    }

    public function test_cloudflare_hostname_is_created_and_removed_when_configured(): void
    {
        config(['platform.custom_domains.cloudflare' => ['token' => 'cf-token', 'zone_id' => 'zone123']]);
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone123/custom_hostnames' => Http::response(['success' => true, 'result' => ['id' => 'host-1']]),
            'api.cloudflare.com/client/v4/zones/zone123/custom_hostnames/host-1' => Http::response(['success' => true]),
            'https://livraison.rapide.ci/*' => Http::response(['slug' => 'rapide']),
        ]);
        Sanctum::actingAs($this->admin);

        $this->putJson($this->api('/company/domain'), ['domain' => 'livraison.rapide.ci'])->assertOk();
        $this->assertSame('host-1', $this->company->fresh()->cloudflare_hostname_id);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['hostname'] === 'livraison.rapide.ci' && $r->hasHeader('Authorization', 'Bearer cf-token'));

        $this->deleteJson($this->api('/company/domain'))->assertOk()->assertJsonPath('data.custom_domain', null);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/custom_hostnames/host-1'));
        $this->assertNull($this->company->fresh()->custom_domain);
    }

    public function test_custom_domain_depends_on_the_plan(): void
    {
        $basic = Plan::create(['name' => 'Essentiel', 'price_monthly' => 25000, 'features' => [], 'is_active' => true, 'position' => 1]);
        $this->company->update(['plan_id' => $basic->id]);
        Sanctum::actingAs($this->admin->fresh());

        $this->getJson($this->api('/company/domain'))->assertOk()->assertJsonPath('data.included', false);
        $this->putJson($this->api('/company/domain'), ['domain' => 'livraison.rapide.ci'])->assertForbidden();
    }
}
