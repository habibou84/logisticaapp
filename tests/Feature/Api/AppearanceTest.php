<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Paramètres › Apparence : couleurs des espaces, noms et icônes des applications installables.
 */
class AppearanceTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        // Pages de l'application rendues sans l'interface compilée (absente en CI)
        $this->withoutVite();
        Storage::fake('local');
        config(['app.branding_company' => $this->company->id]);
    }

    private function spaces(array $override = []): array
    {
        return array_replace_recursive([
            'marchand' => ['color' => '#7C3AED', 'app_name' => 'Express Pro'],
            'livreur' => ['color' => '#be123c', 'app_name' => 'Express Livreur'],
            'admin' => ['color' => '#0e7490'],
        ], $override);
    }

    public function test_each_space_gets_its_own_colors_and_app_names(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/company/appearance')->assertOk()
            ->assertJsonPath('data.spaces.livreur.color', '#1d4ed8');

        $this->putJson('/api/v1/company/appearance', ['same' => false, 'spaces' => $this->spaces()])->assertOk()
            ->assertJsonPath('data.spaces.marchand.color', '#7c3aed')
            ->assertJsonPath('data.spaces.admin.color', '#0e7490');

        // Pages publiques et manifestes des applications
        $this->getJson('/api/v1/branding')->assertJsonPath('data.appearance.spaces.livreur.app_name', 'Express Livreur');
        $this->get('/manifest/livreur.webmanifest')->assertJsonPath('theme_color', '#be123c')
            ->assertJsonPath('short_name', 'Express Livreur');
        $this->get('/livreur/connexion')->assertSee('<meta name="theme-color" content="#be123c">', false);

        // Même apparence : la couleur de l'espace e-commerçant partout, les noms restent propres à chaque application
        $this->putJson('/api/v1/company/appearance', ['same' => true, 'spaces' => $this->spaces()])->assertOk()
            ->assertJsonPath('data.spaces.livreur.color', '#7c3aed')
            ->assertJsonPath('data.spaces.admin.color', '#7c3aed')
            ->assertJsonPath('data.spaces.livreur.app_name', 'Express Livreur');

        // Couleur invalide, ou personne d'autre que l'administrateur
        $this->putJson('/api/v1/company/appearance', ['same' => false, 'spaces' => $this->spaces(['livreur' => ['color' => 'rouge']])])
            ->assertUnprocessable();
        Sanctum::actingAs($this->courierA->user);
        $this->putJson('/api/v1/company/appearance', ['same' => false, 'spaces' => $this->spaces()])->assertForbidden();
    }

    public function test_merchant_app_is_named_after_the_company_by_default(): void
    {
        $this->get('/manifest/marchand.webmanifest')
            ->assertJsonPath('name', $this->company->name)
            ->assertJsonPath('short_name', $this->company->name);
        $this->get('/manifest/livreur.webmanifest')->assertJsonPath('short_name', 'Livreur');

        // Ancien nom par défaut enregistré tel quel : le nom de l'entreprise reprend sa place
        $this->company->update(['appearance' => ['spaces' => ['marchand' => ['app_name' => 'Mes livraisons']]]]);
        $this->get('/manifest/marchand.webmanifest')->assertJsonPath('short_name', $this->company->name);

        // Nom choisi dans Paramètres › Apparence : il l'emporte
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/company/appearance', ['same' => false, 'spaces' => $this->spaces()])->assertOk()
            ->assertJsonPath('data.spaces.marchand.app_name', 'Express Pro')
            ->assertJsonPath('data.spaces.marchand.default_app_name', $this->company->name);
        $this->get('/manifest/marchand.webmanifest')->assertJsonPath('short_name', 'Express Pro');
    }

    public function test_app_icons_come_from_the_logo_or_an_uploaded_image(): void
    {
        // Sans logo ni image : icônes standard
        $this->get('/manifest/marchand.webmanifest')->assertJsonPath('icons.0.src', '/app-icons/marchand-192.png');

        Sanctum::actingAs($this->admin);
        $this->post('/api/v1/company/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 120)])->assertOk();

        // Logo : icône générée sur la couleur de l'espace
        $src = $this->get('/manifest/marchand.webmanifest')->json('icons.0.src');
        $this->assertStringStartsWith('/branding/icon/marchand/192.png?v=', $src);
        $png = $this->get($src)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame([192, 192], array_slice(getimagesizefromstring($png->streamedContent() ?: file_get_contents($png->baseResponse->getFile()->getPathname())), 0, 2));

        // Image carrée envoyée pour l'application livreur, puis retirée
        $this->post('/api/v1/company/appearance/icons/livreur', ['icon' => UploadedFile::fake()->image('icone.png', 512, 512)])
            ->assertOk()->assertJsonPath('data.spaces.livreur.custom_icon', true);
        $this->get('/branding/icon/livreur/maskable-512.png')->assertOk();
        $this->delete('/api/v1/company/appearance/icons/livreur')->assertOk()->assertJsonPath('data.spaces.livreur.custom_icon', false);

        // Image trop petite refusée
        $this->post('/api/v1/company/appearance/icons/livreur', ['icon' => UploadedFile::fake()->image('petite.png', 64, 64)], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }
}
