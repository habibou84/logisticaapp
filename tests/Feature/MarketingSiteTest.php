<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Services\Connect\Directory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Site de présentation sur l'adresse principale de la plateforme (jibiat.com).
 */
class MarketingSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'jibiat.test', 'platform.scheme' => 'https', 'platform.trial_days' => 30, 'marketing.whatsapp' => '0507016739']);
    }

    private function plan(array $attributes = []): Plan
    {
        return Plan::create([
            'name' => 'Essentiel', 'price_monthly' => 25000, 'included_orders' => 500, 'overage_per_order' => 30,
            'max_couriers' => 5, 'features' => ['shop'], 'is_active' => true, ...$attributes,
        ]);
    }

    public function test_home_presents_the_software_with_live_prices(): void
    {
        $plan = $this->plan();
        $this->plan(['name' => 'Ancienne', 'is_active' => false]);

        $this->get('http://jibiat.test/')
            ->assertOk()
            ->assertSee('Trouvez de nouveaux clients : les marchands de Jibiat Marchand cherchent un livreur')
            ->assertSee('Essayer gratuitement 30 jours')
            ->assertSee('https://admin.jibiat.test/creer-mon-entreprise', false)
            ->assertSee('https://admin.jibiat.test/creer-mon-entreprise?formule='.$plan->id, false)
            ->assertSee('https://wa.me/2250507016739?text=', false)
            ->assertSee('25 000')
            ->assertSee('500</strong> courses par mois incluses', false)
            ->assertSee('Boutiques en ligne des marchands')
            ->assertSee('Express DS')
            ->assertSee('Quel bonheur')
            ->assertDontSee('Ancienne')
            // Numéro WhatsApp jamais affiché, seulement utilisé par les boutons
            ->assertDontSee('05 07 01 67 39')
            ->assertSee('href="/conditions"', false)
            ->assertSee('© '.date('Y').' Jibiat SARL', false);

        $this->get('http://www.jibiat.test/tarifs')->assertOk()->assertSee('Des tarifs simples')->assertSee('Jusqu&#039;à 5 livreurs actifs', false);
    }

    public function test_home_speaks_to_delivery_companies_first(): void
    {
        $this->get('http://jibiat.test/')
            ->assertOk()
            ->assertSee('Trouvez des clients.')
            ->assertSee('Les marchands de Jibiat Marchand cherchent un livreur')
            ->assertSee('/marketing/videos/entreprise-productivite-w.mp4', false)
            ->assertSee('Assignation automatique')
            ->assertSee('Trouvez des clients déjà sur Jibiat Marchand')
            ->assertSee('Comment Jibiat m&#039;aide-t-il à trouver des clients ?', false)
            ->assertSee('Dans toutes les formules : les marchands de Jibiat Marchand vous trouvent', false)
            ->assertSee('Compris dans toutes les formules')
            // Les vidéos faites pour les e-commerçants restent sur jibiat-marchand.com
            ->assertDontSee('marchand-livreur-fiable')
            ->assertDontSee('deux-applications');
    }

    public function test_features_page_details_each_area(): void
    {
        $this->plan();

        $this->get('http://jibiat.test/fonctionnalites')
            ->assertOk()
            ->assertSee('Tout ce dont votre entreprise de livraison a besoin')
            ->assertSee('href="#caisse"', false)
            ->assertSee('Paie des livreurs')
            ->assertSee('Application e-commerçant')
            ->assertSee('votre-entreprise.jibiat.test')
            ->assertSee('https://admin.jibiat.test/creer-mon-entreprise', false);

        // Vidéos de démonstration hébergées sur le site
        foreach (['course-de-a-a-z', 'caisse', 'livreur', 'ecommercant', 'whatsapp', 'statistiques'] as $video) {
            $this->assertFileExists(public_path("marketing/videos/{$video}.mp4"));
            $this->assertFileExists(public_path("marketing/videos/{$video}.jpg"));
        }
        $this->get('http://jibiat.test/fonctionnalites')
            ->assertSee('src="/marketing/videos/caisse.mp4"', false)
            ->assertSee('src="/marketing/videos/whatsapp.mp4"', false)
            ->assertSee('poster="/marketing/videos/livreur.jpg"', false);
        $this->get('http://jibiat.test/')
            ->assertSee('src="/marketing/videos/course-de-a-a-z.mp4"', false)
            ->assertSee('preload="none"', false);

        // Le menu et l'accueil y mènent
        $this->get('http://jibiat.test/')->assertSee('href="/fonctionnalites"', false);
    }

    public function test_directory_page_shows_listed_companies_only(): void
    {
        $this->get('http://jibiat.test/annuaire')->assertOk()->assertSee('annuaire ouvre bientôt');

        Company::factory()->create(['slug' => 'rapide', 'name' => 'Rapide Express', 'directory_listed' => true, 'merchant_signup' => true, 'directory_pitch' => 'Livré le jour même.']);
        Company::factory()->create(['slug' => 'discrete', 'name' => 'Discrète Livraison']);
        Directory::forget();

        $this->get('http://jibiat.test/annuaire')->assertOk()
            ->assertSee('Trouvez une entreprise de livraison fiable')
            ->assertSee('Rapide Express')->assertSee('Livré le jour même.')
            ->assertSee('https://rapide.jibiat.test/inscription', false)
            ->assertSee('chiffres affichés dès 20 courses')
            ->assertDontSee('Discrète Livraison');
    }

    public function test_closed_signup_keeps_prices_and_offers_contact(): void
    {
        $this->plan();
        PlatformSetting::put('company_signup', '0');

        $this->get('http://jibiat.test/tarifs')
            ->assertOk()
            ->assertSee('25 000')
            ->assertSee('Nous contacter')
            ->assertDontSee('creer-mon-entreprise');

        // Sans formule : tarifs sur mesure
        Plan::query()->delete();
        $this->get('http://jibiat.test/')->assertOk()->assertSee('Tarifs sur mesure');
    }

    public function test_unknown_page_is_a_404_and_other_addresses_are_unchanged(): void
    {
        $this->get('http://jibiat.test/inconnue')->assertNotFound()->assertSee("Cette page n'existe pas", false);
        $this->getJson('http://jibiat.test/api/v1/zones')->assertNotFound();

        // L'adresse d'une entreprise sert toujours l'application
        Company::factory()->create(['slug' => 'rapide']);
        $this->get('http://rapide.jibiat.test/')->assertOk()->assertDontSee('dans une seule application');
    }
}
