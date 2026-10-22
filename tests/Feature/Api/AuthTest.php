<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = RolesAndPermissionsSeeder::class;

    private function admin(array $attributes = []): User
    {
        return User::factory()->withRole(Role::Admin)->create([
            'phone' => '0701020304',
            'email' => 'admin@example.ci',
            ...$attributes,
        ]);
    }

    public function test_user_can_log_in_with_a_local_phone_number(): void
    {
        $this->admin();

        $this->postJson('/api/v1/auth/login', ['login' => '07 01 02 03 04', 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'phone', 'role', 'permissions', 'company']])
            ->assertJsonPath('user.phone', '+2250701020304')
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('user.role_label', 'Administrateur');
    }

    public function test_user_can_log_in_with_email_case_insensitively(): void
    {
        $user = $this->admin();

        $this->postJson('/api/v1/auth/login', ['login' => 'ADMIN@example.ci', 'password' => 'password'])
            ->assertOk();

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_password_is_rejected_with_a_validation_error(): void
    {
        $this->admin();

        $this->postJson('/api/v1/auth/login', ['login' => '0701020304', 'password' => 'mauvais'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.login.0', 'Identifiant ou mot de passe incorrect.');
    }

    public function test_suspended_user_cannot_log_in(): void
    {
        $this->admin(['status' => 'suspended']);

        $this->postJson('/api/v1/auth/login', ['login' => '0701020304', 'password' => 'password'])
            ->assertForbidden();
    }

    public function test_user_of_a_suspended_company_cannot_log_in(): void
    {
        $this->admin(['company_id' => Company::factory()->suspended()]);

        $this->postJson('/api/v1/auth/login', ['login' => '0701020304', 'password' => 'password'])
            ->assertForbidden();
    }

    public function test_login_is_rate_limited(): void
    {
        $this->admin();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['login' => '0701020304', 'password' => 'mauvais']);
        }

        $this->postJson('/api/v1/auth/login', ['login' => '0701020304', 'password' => 'password'])
            ->assertStatus(429);
    }

    public function test_me_returns_the_authenticated_user_with_permissions(): void
    {
        $user = $this->admin();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonFragment(['users.manage']);
    }

    public function test_guest_receives_a_json_401(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Non authentifié.']);
    }

    public function test_logout_only_revokes_the_current_device_token(): void
    {
        $user = $this->admin();
        $phoneToken = $user->createToken('telephone')->plainTextToken;
        $user->createToken('ordinateur');

        $this->withToken($phoneToken)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertSame(['ordinateur'], PersonalAccessToken::pluck('name')->all());
    }

    public function test_suspended_user_with_an_existing_token_is_blocked(): void
    {
        $user = $this->admin();
        $token = $user->createToken('test')->plainTextToken;
        $user->update(['status' => 'suspended']);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden();
        $this->assertSame(0, PersonalAccessToken::count());
    }
}
