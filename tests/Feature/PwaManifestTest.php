<?php

namespace Tests\Feature;

use Tests\TestCase;

class PwaManifestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pas de build front-end dans le job de tests
        $this->withoutVite();
    }

    public function test_each_mobile_app_has_its_own_manifest(): void
    {
        $this->get('/manifest/livreur.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('id', '/livreur')
            ->assertJsonPath('start_url', '/livreur?source=pwa&app=livreur')
            ->assertJsonPath('scope', '/livreur')
            ->assertJsonPath('display', 'standalone');

        $this->get('/manifest/marchand.webmanifest')
            ->assertOk()
            ->assertJsonPath('id', '/marchand')
            ->assertJsonPath('start_url', '/marchand?source=pwa&app=marchand')
            // Portées séparées : sinon Chrome dit l'autre application « déjà installée »
            ->assertJsonPath('scope', '/marchand');
    }

    public function test_manifest_icons_exist_outside_the_apache_icons_alias(): void
    {
        foreach (['livreur', 'marchand'] as $app) {
            foreach ($this->get("/manifest/{$app}.webmanifest")->json('icons') as $icon) {
                // Sous Debian, Apache sert /icons/ depuis /usr/share/apache2/icons : l'icône serait introuvable
                $this->assertStringStartsNotWith('/icons/', $icon['src']);
                $this->assertFileExists(public_path($icon['src']));
            }
        }
    }

    public function test_back_office_has_no_manifest(): void
    {
        // Pas de route : la page de l'application (HTML) est servie à la place
        $response = $this->get('/manifest/admin.webmanifest');
        $this->assertStringNotContainsString('manifest+json', (string) $response->headers->get('Content-Type'));
    }

    public function test_pages_link_the_right_manifest(): void
    {
        $this->get('/livreur')->assertOk()->assertSee('/manifest/livreur.webmanifest', false);
        $this->get('/marchand')->assertOk()->assertSee('/manifest/marchand.webmanifest', false);
        $this->get('/admin')->assertOk()->assertDontSee('webmanifest', false);
    }
}
