<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Identité visuelle des pages publiques : nom, accroche, logo de l'entreprise.
 */
class BrandingTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Storage::fake('local');
    }

    public function test_public_branding_and_logo_upload(): void
    {
        $this->company->update(['tagline' => 'Vos colis livrés le jour même']);

        $this->getJson('/api/v1/branding')->assertOk()
            ->assertJsonPath('data.name', $this->company->name)
            ->assertJsonPath('data.tagline', 'Vos colis livrés le jour même')
            ->assertJsonPath('data.logo_url', null);
        $this->get('/branding/logo')->assertNotFound();

        // Seul un administrateur (paramètres) envoie le logo
        Sanctum::actingAs($this->dispatcher);
        $this->post('/api/v1/company/logo', ['logo' => UploadedFile::fake()->image('logo.png', 200, 200)], ['Accept' => 'application/json'])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->post('/api/v1/company/logo', ['logo' => UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml')], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('logo');
        $this->post('/api/v1/company/logo', ['logo' => UploadedFile::fake()->image('petit.png', 20, 20)], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('logo');

        $url = $this->post('/api/v1/company/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 120)], ['Accept' => 'application/json'])
            ->assertOk()->json('data.logo_url');
        $this->assertStringStartsWith('/branding/logo?v=', $url);
        $first = $this->company->fresh()->logo_path;
        Storage::disk('local')->assertExists($first);

        $this->getJson('/api/v1/branding')->assertJsonPath('data.logo_url', $url);
        $this->getJson("/api/v1/companies/{$this->company->id}")->assertJsonPath('data.logo_url', $url);
        $this->get('/branding/logo')->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');

        // Remplacement : l'ancien fichier disparaît ; suppression
        $this->post('/api/v1/company/logo', ['logo' => UploadedFile::fake()->image('logo2.jpg', 300, 120)], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('local')->assertMissing($first);
        $this->deleteJson('/api/v1/company/logo')->assertOk()->assertJsonPath('data.logo_url', null);
        $this->assertNull($this->company->fresh()->logo_path);

        // Accroche modifiable dans les paramètres
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['tagline' => 'Livraison express à Abidjan'])
            ->assertOk()->assertJsonPath('data.tagline', 'Livraison express à Abidjan');
    }
}
