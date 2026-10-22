<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Politique de confidentialité et conditions générales : publiques (exigées par Meta), au nom de l'entreprise.
 */
class PrivacyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_page_is_public_and_names_the_company(): void
    {
        Company::factory()->create(['name' => 'Rapide Express', 'email' => 'contact@rapide.test']);

        $this->get('/confidentialite')->assertOk()
            ->assertSee('Politique de confidentialité')
            ->assertSee('Rapide Express')
            ->assertSee('id="suppression"', false)
            ->assertSee('contact@rapide.test')
            ->assertSee('href="/conditions"', false);
    }

    public function test_legal_settings_replace_the_company_details(): void
    {
        config(['platform.legal' => ['name' => 'Logistica SARL', 'address' => 'Cocody, Abidjan', 'email' => 'legal@logistica.test', 'rccm' => 'CI-ABJ-2026-B-12345']]);
        Company::factory()->create(['name' => 'Rapide Express']);

        $this->get('/conditions')->assertOk()
            ->assertSee("Conditions générales d'utilisation", false)
            ->assertSee('Logistica SARL')
            ->assertSee('Cocody, Abidjan')
            ->assertSee('CI-ABJ-2026-B-12345')
            ->assertSee('mailto:legal@logistica.test', false)
            ->assertDontSee('abonnement');

        $this->get('/confidentialite')->assertOk()->assertSee('Logistica SARL');
    }

    public function test_pages_work_before_the_company_is_created(): void
    {
        $this->get('/conditions')->assertOk();
        $this->get('/confidentialite')->assertOk();
    }
}
