<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Chaque page de connexion n'ouvre que les comptes de son espace (e-commerçant, livreur, équipe).
 */
class LoginSpaceTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function login(string $phone, string $space)
    {
        return $this->postJson('/api/v1/auth/login', ['login' => $phone, 'password' => 'password', 'space' => $space]);
    }

    public function test_each_login_page_only_opens_its_own_accounts(): void
    {
        $courier = $this->courierA->user->phone;
        $merchant = $this->merchantUser->phone;
        $staff = $this->dispatcher->phone;

        // Livreur sur la page des e-commerçants : refusé, avec l'espace où aller, sans session ouverte
        $this->login($courier, 'merchant')->assertStatus(422)
            ->assertJsonPath('space', 'courier')
            ->assertJsonPath('message', 'Ce compte est un compte livreur : connectez-vous depuis l\'espace livreur.')
            ->assertJsonMissingPath('token');
        $this->assertSame(0, $this->courierA->user->tokens()->count());

        $this->login($merchant, 'courier')->assertStatus(422)->assertJsonPath('space', 'merchant');
        $this->login($staff, 'merchant')->assertStatus(422)->assertJsonPath('space', 'staff');
        $this->login($merchant, 'staff')->assertStatus(422)->assertJsonPath('space', 'merchant');

        // Chacun sur sa page : connecté
        $this->login($courier, 'courier')->assertOk()->assertJsonStructure(['token']);
        $this->login($merchant, 'merchant')->assertOk();
        $this->login($staff, 'staff')->assertOk();

        // Mauvais mot de passe : rien n'indique l'espace du compte
        $this->postJson('/api/v1/auth/login', ['login' => $courier, 'password' => 'faux', 'space' => 'merchant'])
            ->assertStatus(422)->assertJsonMissingPath('space');
    }
}
