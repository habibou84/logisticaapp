<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Un fichier compilé disparu (ancienne version après une mise à jour) répond 404 : jamais la page de
 * l'application à sa place, qui serait prise pour du JavaScript et gardée par le service worker.
 */
class StaleAssetTest extends TestCase
{
    public function test_a_missing_build_file_is_a_404_but_pages_still_serve_the_app(): void
    {
        $this->withoutVite();

        $this->get('/build/assets/Planning-ancienne.js')->assertNotFound();
        $this->get('/admin/planning')->assertOk()->assertSee('<div id="app">', false);
    }
}
