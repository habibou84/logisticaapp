<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Applications Android et iOS : entreprise retrouvée par son code, API appelée depuis l'application.
 */
class NativeAppTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://app.logistica.test']);
        $this->buildWorld();
        $this->company->update(['slug' => 'rapide', 'name' => 'Rapide Express']);
    }

    public function test_company_is_found_by_its_code(): void
    {
        $this->getJson('/api/public/apps/companies/Rapide')
            ->assertOk()
            ->assertJsonPath('data.code', 'rapide')
            ->assertJsonPath('data.name', 'Rapide Express')
            ->assertJsonPath('data.url', 'https://app.logistica.test')
            ->assertJsonPath('data.logo_url', null);

        // Logo : lien absolu vers le site
        $this->company->forceFill(['logo_path' => 'branding/logo.png'])->save();
        $this->assertStringStartsWith('https://app.logistica.test/branding/logo?v=',
            $this->getJson('/api/public/apps/companies/rapide')->json('data.logo_url'));
    }

    public function test_unknown_or_suspended_company_is_not_found(): void
    {
        $this->getJson('/api/public/apps/companies/inconnue')
            ->assertNotFound()
            ->assertJsonPath('message', 'Code introuvable. Vérifiez-le auprès de votre entreprise de livraison.');

        Company::factory()->create(['slug' => 'fermee', 'status' => 'suspended']);
        $this->getJson('/api/public/apps/companies/fermee')->assertNotFound();
    }

    public function test_apps_may_call_the_company_api(): void
    {
        foreach (['https://localhost', 'capacitor://localhost'] as $origin) {
            $this->call('OPTIONS', '/api/v1/auth/login', server: [
                'HTTP_ORIGIN' => $origin,
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
            ])->assertHeader('Access-Control-Allow-Origin', $origin);
        }

        // Une autre origine n'est pas autorisée
        $this->call('OPTIONS', '/api/v1/auth/login', server: [
            'HTTP_ORIGIN' => 'https://exemple.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
