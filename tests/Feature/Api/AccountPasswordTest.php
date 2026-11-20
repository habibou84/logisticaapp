<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Mon compte : la personne connectée change son mot de passe (mot de passe actuel demandé).
 */
class AccountPasswordTest extends TestCase
{
    use BuildsDeliveryWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_a_signed_in_user_changes_his_password(): void
    {
        $this->dispatcher->forceFill(['password' => 'ancien-secret'])->save();
        $elsewhere = $this->dispatcher->createToken('autre appareil');
        Sanctum::actingAs($this->dispatcher);

        $new = ['password' => 'nouveau-secret', 'password_confirmation' => 'nouveau-secret'];
        $this->putJson('/api/v1/auth/password', $new)->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v1/auth/password', [...$new, 'current_password' => 'faux'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v1/auth/password', ['current_password' => 'ancien-secret', 'password' => 'court', 'password_confirmation' => 'court'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->putJson('/api/v1/auth/password', [...$new, 'current_password' => 'ancien-secret'])->assertOk()
            ->assertJsonPath('user.has_password', true)->assertJsonPath('message', 'Mot de passe changé.');
        $this->assertTrue(Hash::check('nouveau-secret', $this->dispatcher->fresh()->password));
        // Les autres appareils sont déconnectés
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $elsewhere->accessToken->id]);
    }

    public function test_changing_the_password_needs_a_session(): void
    {
        $this->putJson('/api/v1/auth/password', ['password' => 'nouveau-secret', 'password_confirmation' => 'nouveau-secret'])->assertUnauthorized();
    }
}
