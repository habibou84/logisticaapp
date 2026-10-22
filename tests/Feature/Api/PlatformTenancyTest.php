<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Company;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Plateforme multi-entreprises : l'adresse ({slug}.jibiat.test) désigne l'entreprise.
 */
class PlatformTenancyTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'jibiat.test']);
        $this->buildWorld();
        $this->company->update(['slug' => 'rapide', 'name' => 'Rapide Express', 'merchant_signup' => true]);
        $this->other = Company::factory()->create(['slug' => 'eclair', 'name' => 'Éclair Livraison', 'merchant_signup' => false]);
    }

    private function at(string $sub, string $path): string
    {
        return 'http://'.($sub === '' ? '' : "{$sub}.").'jibiat.test'.$path;
    }

    private function login(string $sub, string $login, string $password = 'password')
    {
        return $this->postJson($this->at($sub, '/api/v1/auth/login'), ['login' => $login, 'password' => $password]);
    }

    public function test_each_address_shows_its_company(): void
    {
        $this->getJson($this->at('rapide', '/api/v1/branding'))->assertOk()
            ->assertJsonPath('data.name', 'Rapide Express')->assertJsonPath('data.signup_open', true);
        $this->getJson($this->at('eclair', '/api/v1/branding'))->assertOk()->assertJsonPath('data.name', 'Éclair Livraison');

        // Application installable au nom de l'entreprise
        $this->get($this->at('eclair', '/manifest/marchand.webmanifest'))->assertJsonPath('name', 'Éclair Livraison')
            ->assertJsonPath('short_name', 'Éclair Livraison');

        // Inscription des marchands : zones de l'entreprise de l'adresse
        $zones = collect($this->getJson($this->at('rapide', '/api/v1/signup'))->json('data.zones'))->pluck('name');
        $this->assertContains('Cocody', $zones);
        $this->getJson($this->at('eclair', '/api/v1/signup'))->assertJsonPath('data.open', false);
    }

    public function test_platform_address_unknown_and_suspended_companies(): void
    {
        $this->get($this->at('', '/'))->assertOk()->assertSee('votre-entreprise.jibiat.test');
        // Espace d'une entreprise demandé sur l'adresse de la plateforme : page qui oriente vers la bonne adresse
        $this->get($this->at('www', '/admin'))->assertNotFound()->assertSee('votre-entreprise.jibiat.test');
        $this->getJson($this->at('', '/api/v1/branding'))->assertNotFound();

        $this->get($this->at('inconnue', '/'))->assertNotFound()->assertSee('Aucune entreprise à cette adresse.');
        $this->getJson($this->at('inconnue', '/api/v1/branding'))->assertNotFound()->assertJsonPath('message', 'Aucune entreprise à cette adresse.');

        $this->other->update(['status' => 'suspended']);
        $this->get($this->at('eclair', '/'))->assertForbidden()->assertSee('suspendu');

        // Webhooks et API publique : indépendants de l'adresse
        $this->getJson($this->at('', '/api/webhooks/whatsapp'))->assertStatus(403);
        $this->getJson($this->at('inconnue', '/api/public/v1/zones'))->assertUnauthorized();
    }

    public function test_accounts_only_work_at_their_company_address(): void
    {
        $this->login('rapide', '0700000099')->assertJsonValidationErrors('login');
        $this->dispatcher->update(['phone' => '0700000099']);

        $this->login('eclair', '0700000099')->assertJsonValidationErrors('login');
        $token = $this->login('rapide', '0700000099')->assertOk()->json('token');

        // Jeton de « rapide » utilisé à l'adresse d'« eclair » : refusé
        $this->withToken($token)->getJson($this->at('eclair', '/api/v1/auth/me'))->assertUnauthorized()
            ->assertJsonPath('message', 'Ce compte n\'appartient pas à cette entreprise.');
        $this->withToken($token)->getJson($this->at('rapide', '/api/v1/auth/me'))->assertOk();

        // Console : seulement le super administrateur
        $this->login('admin', '0700000099')->assertJsonValidationErrors('login');
        User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null, 'phone' => '0700000777']);
        $this->login('admin', '0700000777')->assertOk();
        $this->login('rapide', '0700000777')->assertJsonValidationErrors('login');

        // Mot de passe oublié : le numéro n'est cherché que dans l'entreprise de l'adresse
        $this->postJson($this->at('eclair', '/api/v1/password/forgot'), ['phone' => '0700000099'])->assertCreated()
            ->assertJsonPath('data.purpose', 'password_reset');
        $this->assertSame($this->other->id, PhoneVerification::latest('id')->value('company_id'));
    }

    public function test_tracking_and_shops_stay_within_their_company(): void
    {
        $order = app(OrderWorkflow::class)->transition($this->dispatcher, $this->createOrder(), OrderStatus::Confirmed);
        $this->getJson($this->at('rapide', "/api/v1/tracking/{$order->tracking_code}"))->assertOk();
        $this->getJson($this->at('eclair', "/api/v1/tracking/{$order->tracking_code}"))->assertNotFound();

        $this->merchant->update(['shop_slug' => 'chic', 'shop_enabled' => true]);
        $this->getJson($this->at('rapide', '/api/v1/shops/chic'))->assertOk();
        $this->getJson($this->at('eclair', '/api/v1/shops/chic'))->assertNotFound();
    }

    public function test_links_sent_to_customers_use_the_company_address(): void
    {
        $this->assertSame('https://rapide.jibiat.test/suivi/LV-1', $this->company->url('/suivi/LV-1'));
        $this->assertSame('https://eclair.jibiat.test', $this->other->url());

        config(['platform.domain' => null, 'app.url' => 'https://livraison.example']);
        $this->assertSame('https://livraison.example/suivi/LV-1', $this->company->url('/suivi/LV-1'));
    }

    // ───────────── Étape 2 : comptes par entreprise ─────────────

    public function test_the_same_number_can_have_an_account_in_each_company(): void
    {
        $this->dispatcher->update(['phone' => '0711223344']);
        $twin = User::factory()->withRole(Role::Courier)->create(['company_id' => $this->other->id, 'phone' => '0711223344', 'password' => 'autremotdepasse']);

        $this->assertSame($this->company->id, $this->login('rapide', '0711223344')->assertOk()->json('user.company_id'));
        $token = $this->login('eclair', '0711223344', 'autremotdepasse')->assertOk()->json('token');
        $this->assertSame($twin->id, $this->withToken($token)->getJson($this->at('eclair', '/api/v1/auth/me'))->json('data.id'));

        // Le mot de passe d'une entreprise ne vaut pas pour l'autre
        $this->login('eclair', '0711223344')->assertJsonValidationErrors('login');

        // Dans une même entreprise, le numéro reste unique
        Sanctum::actingAs($this->admin);
        $this->postJson($this->at('rapide', '/api/v1/users'), ['name' => 'Doublon', 'phone' => '07 11 22 33 44', 'password' => 'password', 'role' => 'courier'])
            ->assertJsonValidationErrors('phone');
    }

    public function test_a_merchant_of_one_company_can_sign_up_with_another(): void
    {
        $this->other->update(['merchant_signup' => true]);
        $zone = $this->zone('Treichville', null, $this->other);

        // Le gérant du marchand de « rapide » s'inscrit chez « eclair » avec le même numéro
        $this->postJson($this->at('eclair', '/api/v1/signup'), [
            'business_name' => 'Boutique Test', 'contact_name' => 'Mariam', 'phone' => $this->merchantUser->phone,
            'pickup_zone_id' => $zone->id, 'pickup_address' => 'Avenue 12',
            'password' => 'motdepasse', 'password_confirmation' => 'motdepasse',
        ])->assertCreated();

        // Mais pas deux fois dans la même entreprise
        $this->postJson($this->at('rapide', '/api/v1/signup'), [
            'business_name' => 'Boutique Bis', 'contact_name' => 'Mariam', 'phone' => $this->merchantUser->phone,
            'pickup_zone_id' => $this->cocody->id, 'pickup_address' => 'Riviera',
            'password' => 'motdepasse', 'password_confirmation' => 'motdepasse',
        ])->assertUnprocessable();
    }

    public function test_without_the_platform_an_ambiguous_number_must_use_its_company_address(): void
    {
        config(['platform.domain' => null]);
        $this->dispatcher->update(['phone' => '0711223344']);
        User::factory()->withRole(Role::Courier)->create(['company_id' => $this->other->id, 'phone' => '0711223344', 'password' => 'autremotdepasse']);

        // Mots de passe différents : le bon compte est trouvé
        $this->assertSame($this->other->id, $this->postJson('/api/v1/auth/login', ['login' => '0711223344', 'password' => 'autremotdepasse'])->assertOk()->json('user.company_id'));

        // Même mot de passe dans les deux entreprises : impossible de choisir
        User::query()->where('phone', '+2250711223344')->update(['password' => bcrypt('password')]);
        $this->postJson('/api/v1/auth/login', ['login' => '0711223344', 'password' => 'password'])
            ->assertJsonPath('errors.login.0', 'Ce numéro a un compte dans plusieurs entreprises : connectez-vous depuis l\'adresse de votre entreprise.');
    }
}
