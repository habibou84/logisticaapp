<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\ConnectRequest;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\ConnectRequestReceived;
use App\Services\Connect\Directory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Jibiat\Socle\Contract\Contract;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Annuaire des entreprises de livraison et connexion en un clic de Jibiat Marchand (contrat : directory,
 * connect-token) : l'e-commerçant autorise l'accès, l'application échange le code contre une clé.
 */
class AppConnectTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const REDIRECT = 'https://awa.jibiat-marchand.com/gestion/livraisons/entreprises/retour';

    private const VERIFIER = 'un-code-verifier-assez-long-pour-pkce-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.domain' => 'jibiat.test']);
        $this->buildWorld();
        $this->company->update(['slug' => 'rapide', 'name' => 'Rapide Express', 'merchant_signup' => true]);
    }

    private function at(string $sub, string $path): string
    {
        return 'http://'.($sub === '' ? '' : "{$sub}.").'jibiat.test'.$path;
    }

    private static function challenge(string $verifier = self::VERIFIER): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function authorizeAs(User $user, array $extra = []): string
    {
        Sanctum::actingAs($user);

        return $this->postJson($this->at('rapide', '/api/v1/connect/authorize'), [
            'client_id' => 'jibiat-marchand', 'redirect_uri' => self::REDIRECT, 'state' => 'etat-123',
            'code_challenge' => self::challenge(), 'code_challenge_method' => 'S256', ...$extra,
        ])->assertOk()->json('data.redirect');
    }

    private function token(string $code, string $verifier = self::VERIFIER)
    {
        return $this->postJson($this->at('rapide', '/api/public/connect/token'), [
            'grant_type' => 'authorization_code', 'client_id' => 'jibiat-marchand', 'code' => $code,
            'code_verifier' => $verifier, 'redirect_uri' => self::REDIRECT,
        ]);
    }

    public function test_directory_lists_only_companies_that_chose_it_with_their_real_figures(): void
    {
        Company::factory()->create(['slug' => 'discrete', 'name' => 'Discrète Livraison']);
        $this->cocody->update(['city' => 'Abidjan']);
        $this->company->update(['directory_listed' => true, 'directory_pitch' => 'Livré le jour même dans tout Abidjan.']);

        foreach (range(1, 24) as $i) {
            $this->createOrder()->forceFill(['status' => $i <= 21 ? OrderStatus::Delivered : OrderStatus::Returned, 'delivered_at' => $i <= 21 ? now()->addHours(4) : null])->saveQuietly();
        }
        Directory::forget();

        $response = $this->getJson($this->at('', '/api/public/directory'))->assertOk();
        $this->assertSame([], Contract::validate('directory', $response->json()));
        $this->assertCount(1, $response->json('data'));
        $entry = $response->json('data.0');
        $this->assertSame('rapide', $entry['slug']);
        $this->assertSame('https://rapide.jibiat.test/marchand/connecter', $entry['connect_url']);
        $this->assertSame('Livré le jour même dans tout Abidjan.', $entry['pitch']);
        $this->assertSame('signup', $entry['accepts']);
        $this->assertSame(1000, $entry['price_from']);
        $this->assertContains('Abidjan', $entry['cities']);
        $this->assertSame(21, $entry['stats']['deliveries_90d']);
        $this->assertSame(0.88, $entry['stats']['success_rate']);
        $this->assertEqualsWithDelta(4.0, $entry['stats']['average_delivery_hours'], 0.2);

        // L'annuaire est ouvert à toutes les formules, même sans le module Intégrations
        $basic = Plan::create(['name' => 'Essentiel', 'price_monthly' => 25000, 'features' => [], 'is_active' => true, 'position' => 1]);
        $this->company->update(['plan_id' => $basic->id]);
        $this->getJson($this->at('', '/api/public/directory'))->assertJsonCount(1, 'data');
        $this->getJson($this->at('rapide', '/api/public/connect/client?'.http_build_query(['client_id' => 'jibiat-marchand', 'redirect_uri' => self::REDIRECT])))
            ->assertOk();
        $this->company->update(['plan_id' => null]);

        // Retrait de l'annuaire : aussitôt invisible
        $this->company->update(['directory_listed' => false]);
        $this->getJson($this->at('', '/api/public/directory'))->assertJsonCount(0, 'data');
    }

    public function test_the_agency_gets_its_jibiat_marchand_invitation_link(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson($this->at('rapide', '/api/v1/auth/me'))->assertOk()
            ->assertJsonPath('data.company.jibiat_marchand_invite_url', 'https://jibiat-marchand.com/creer-ma-boutique?partenaire=rapide');
    }

    public function test_figures_stay_hidden_under_twenty_orders(): void
    {
        $this->company->update(['directory_listed' => true, 'merchant_signup' => false]);
        $this->createOrder()->forceFill(['status' => OrderStatus::Delivered, 'delivered_at' => now()])->saveQuietly();
        Directory::forget();

        $entry = $this->getJson($this->at('', '/api/public/directory'))->json('data.0');
        $this->assertSame(1, $entry['stats']['deliveries_90d']);
        $this->assertNull($entry['stats']['success_rate']);
        $this->assertNull($entry['stats']['average_delivery_hours']);
        $this->assertSame('request', $entry['accepts']);
    }

    public function test_merchant_connects_jibiat_marchand_in_one_click(): void
    {
        // Écran « Connecter » : application et adresse de retour reconnues
        $this->getJson($this->at('rapide', '/api/public/connect/client?'.http_build_query(['client_id' => 'jibiat-marchand', 'redirect_uri' => self::REDIRECT])))
            ->assertOk()->assertJsonPath('data.client.name', 'Jibiat Marchand')->assertJsonPath('data.company.name', 'Rapide Express')
            ->assertJsonPath('data.accepts', 'signup');

        $redirect = $this->authorizeAs($this->merchantUser);
        $this->assertStringStartsWith(self::REDIRECT.'?code=', $redirect);
        parse_str(parse_url($redirect, PHP_URL_QUERY), $query);
        $this->assertSame('etat-123', $query['state']);

        $this->app['auth']->forgetGuards();
        $response = $this->token($query['code'])->assertOk();
        $this->assertSame([], Contract::validate('connect-token', $response->json()));
        $response->assertJsonPath('data.merchant.id', $this->merchant->id)->assertJsonPath('data.company.slug', 'rapide');
        $this->assertContains('webhooks:write', $response->json('data.scopes'));

        // La clé reçue ouvre l'API publique au nom du marchand
        $this->getJson($this->at('rapide', '/api/public/v1/me'), ['Authorization' => 'Bearer '.$response->json('data.api_key')])
            ->assertOk()->assertJsonPath('data.merchant.id', $this->merchant->id);
        $this->assertSame('Jibiat Marchand', ApiKey::withoutGlobalScopes()->latest('id')->value('name'));

        // Code à usage unique
        $this->token($query['code'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_a_key_from_the_directory_works_without_the_integrations_module(): void
    {
        $basic = Plan::create(['name' => 'Essentiel', 'price_monthly' => 25000, 'features' => [], 'is_active' => true, 'position' => 1]);
        $this->company->update(['plan_id' => $basic->id]);

        parse_str(parse_url($this->authorizeAs($this->merchantUser), PHP_URL_QUERY), $query);
        $this->app['auth']->forgetGuards();
        $connected = $this->token($query['code'])->assertOk()->json('data.api_key');
        $this->assertSame('jibiat-marchand', ApiKey::withoutGlobalScopes()->latest('id')->value('client'));

        // Clé de Jibiat Marchand (connexion en un clic) : acceptée ; clé créée à la main : réservée au module Intégrations
        $this->getJson($this->at('rapide', '/api/public/v1/me'), ['Authorization' => 'Bearer '.$connected])->assertOk();
        [, $manual] = ApiKey::issue($this->merchant, 'Site maison', ['orders:read'], null);
        $this->getJson($this->at('rapide', '/api/public/v1/me'), ['Authorization' => 'Bearer '.$manual])->assertStatus(403);
    }

    public function test_a_wrong_verifier_an_expired_code_or_a_foreign_address_is_refused(): void
    {
        parse_str(parse_url($this->authorizeAs($this->merchantUser), PHP_URL_QUERY), $query);
        $this->app['auth']->forgetGuards();
        $this->token($query['code'], 'un-autre-code-verifier-qui-ne-correspond-pas-0123456789')->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        // Après une tentative fausse, le code ne sert plus
        $this->token($query['code'])->assertStatus(400);

        parse_str(parse_url($this->authorizeAs($this->merchantUser), PHP_URL_QUERY), $query);
        $this->travel(11)->minutes();
        $this->app['auth']->forgetGuards();
        $this->token($query['code'])->assertStatus(400);

        // Adresse de retour d'un autre site, ou application inconnue
        Sanctum::actingAs($this->merchantUser);
        $this->postJson($this->at('rapide', '/api/v1/connect/authorize'), [
            'client_id' => 'jibiat-marchand', 'redirect_uri' => 'https://pirate.example/retour', 'state' => 's', 'code_challenge' => self::challenge(),
        ])->assertStatus(422)->assertJsonValidationErrors('redirect_uri');
        $this->getJson($this->at('rapide', '/api/public/connect/client?client_id=inconnue&redirect_uri='.urlencode(self::REDIRECT)))->assertStatus(422);

        // Seul le gérant d'une boutique autorise une application
        Sanctum::actingAs($this->dispatcher);
        $this->postJson($this->at('rapide', '/api/v1/connect/authorize'), [
            'client_id' => 'jibiat-marchand', 'redirect_uri' => self::REDIRECT, 'state' => 's', 'code_challenge' => self::challenge(),
        ])->assertStatus(422);
    }

    public function test_closed_signup_becomes_a_request_the_agency_accepts(): void
    {
        Notification::fake();
        $this->company->update(['merchant_signup' => false]);

        $this->getJson($this->at('rapide', '/api/public/connect/client?'.http_build_query(['client_id' => 'jibiat-marchand', 'redirect_uri' => self::REDIRECT])))
            ->assertJsonPath('data.accepts', 'request');

        $this->postJson($this->at('rapide', '/api/public/connect/requests'), [
            'client_id' => 'jibiat-marchand', 'business_name' => 'Awa Mode', 'contact_name' => 'Awa Koné', 'phone' => '0707112233',
            'pickup_zone_id' => $this->cocody->id, 'pickup_address' => 'Riviera 3', 'message' => '30 colis par semaine',
            'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1',
        ])->assertCreated()->assertJsonPath('data.status', 'pending');
        Notification::assertSentTo($this->admin, ConnectRequestReceived::class);

        // Un numéro déjà client est renvoyé vers la connexion
        $this->postJson($this->at('rapide', '/api/public/connect/requests'), [
            'business_name' => 'X', 'contact_name' => 'X', 'phone' => $this->merchant->phone, 'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1',
        ])->assertStatus(422);

        Sanctum::actingAs($this->admin);
        $list = $this->getJson($this->at('rapide', '/api/v1/connect-requests'))->assertOk()->assertJsonPath('pending', 1);
        $this->assertSame('Jibiat Marchand', $list->json('data.0.from_app'));
        $id = $list->json('data.0.id');

        $merchantId = $this->postJson($this->at('rapide', "/api/v1/connect-requests/{$id}/accept"))->assertOk()->json('data.merchant_id');
        $this->assertSame('Awa Mode', Merchant::withoutGlobalScopes()->find($merchantId)->business_name);
        $this->assertNull(ConnectRequest::withoutGlobalScopes()->find($id)->password_hash);
        $this->postJson($this->at('rapide', "/api/v1/connect-requests/{$id}/accept"))->assertStatus(422);

        // Le gérant se connecte avec le mot de passe qu'il a choisi
        $this->app['auth']->forgetGuards();
        $this->postJson($this->at('rapide', '/api/v1/auth/login'), ['login' => '0707112233', 'password' => 'motdepasse1'])->assertOk();

        // Les autres membres de l'équipe sans le droit ne voient pas les demandes
        Sanctum::actingAs($this->courierA->user);
        $this->getJson($this->at('rapide', '/api/v1/connect-requests'))->assertForbidden();
    }
}
