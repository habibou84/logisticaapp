<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Http\Middleware\PreprodGate;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Comptes de test affichés sur les pages de connexion : préproduction seulement,
 * comptes de l'entreprise au mot de passe de démonstration, jamais le super administrateur.
 */
class DemoAccountsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        config(['app.branding_company' => $this->company->id]);
        User::query()->update(['password' => Hash::make('password')]);
    }

    private function demoAccounts()
    {
        $pass = hash_hmac('sha256', 'mangue-2026', (string) config('app.key'));

        return $this->withCredentials()->withUnencryptedCookie(PreprodGate::COOKIE, $pass)->getJson('/api/v1/demo-accounts');
    }

    public function test_preproduction_lists_the_company_test_accounts_by_space(): void
    {
        config(['platform.preprod_access_code' => 'mangue-2026']);
        $root = User::factory()->create(['company_id' => null, 'password' => 'password']);
        $root->assignRole(Role::SuperAdmin->value);
        // Mot de passe changé : plus un compte de test
        $this->courierB->user->update(['password' => 'un-vrai-secret']);
        // Compte d'une autre entreprise : jamais affiché
        $foreign = $this->userWithRole(Role::Admin, ['password' => 'password'], Company::factory()->create());

        $accounts = collect($this->demoAccounts()->assertOk()->json('data'));

        $this->assertSame('password', $accounts->first()['password']);
        $this->assertNotContains(Role::SuperAdmin->value, $accounts->pluck('role'));
        $this->assertContains($this->admin->id, User::whereIn('phone', $accounts->pluck('login')->map(fn ($l) => '+225'.$l))->pluck('id'));
        $this->assertSame(['staff', 'courier', 'merchant'], $accounts->pluck('space')->unique()->values()->all());
        $this->assertNotContains(substr($this->courierB->user->phone, 4), $accounts->pluck('login'));
        $this->assertNotContains(substr($foreign->phone, 4), $accounts->pluck('login'));
    }

    public function test_hidden_outside_preproduction_and_without_the_access_code(): void
    {
        config(['platform.preprod_access_code' => '']);
        $this->getJson('/api/v1/demo-accounts')->assertNotFound();

        config(['platform.preprod_access_code' => 'mangue-2026']);
        $this->getJson('/api/v1/demo-accounts')->assertForbidden();

        $this->app->detectEnvironment(fn () => 'production');
        $this->demoAccounts()->assertNotFound();
    }
}
