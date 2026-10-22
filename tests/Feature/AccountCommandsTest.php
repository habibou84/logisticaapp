<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Commandes d'exploitation : mot de passe d'un compte, comptes de démonstration.
 */
class AccountCommandsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    // Base préparée avec les rôles, comme les autres tests qui en dépendent (ordre des fichiers)
    protected bool $seed = true;

    protected string $seeder = RolesAndPermissionsSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_password_change_disconnects_the_account(): void
    {
        $root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null, 'phone' => '0700000000', 'name' => 'Super administrateur']);
        $root->createToken('web');

        // Super administrateur : 12 caractères minimum
        $this->artisan('user:password', ['login' => '07 00 00 00 00'])
            ->expectsQuestion('Nouveau mot de passe (12 caractères minimum)', 'court')
            ->expectsOutputToContain('au moins 12 caractères')
            ->assertFailed();

        $this->artisan('user:password', ['login' => '0700000000'])
            ->expectsQuestion('Nouveau mot de passe (12 caractères minimum)', 'UnTresBonMotDePasse')
            ->expectsQuestion('Confirmez le mot de passe', 'UnAutre')
            ->expectsOutputToContain('ne correspondent pas')
            ->assertFailed();

        $this->artisan('user:password', ['login' => '0700000000'])
            ->expectsQuestion('Nouveau mot de passe (12 caractères minimum)', 'UnTresBonMotDePasse')
            ->expectsQuestion('Confirmez le mot de passe', 'UnTresBonMotDePasse')
            ->expectsOutputToContain('Mot de passe changé pour Super administrateur (console de la plateforme)')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('UnTresBonMotDePasse', $root->fresh()->password));
        $this->assertSame(0, $root->tokens()->count());
    }

    public function test_a_number_used_in_several_places_needs_the_company(): void
    {
        $other = Company::factory()->create(['slug' => 'eclair']);
        $this->dispatcher->update(['phone' => '0711223344']);
        $twin = User::factory()->withRole(Role::Courier)->create(['company_id' => $other->id, 'phone' => '0711223344']);

        $this->artisan('user:password', ['login' => '0711223344'])
            ->expectsOutputToContain('--company=eclair')
            ->assertFailed();

        $this->artisan('user:password', ['login' => '0711223344', '--company' => 'eclair'])
            ->expectsQuestion('Nouveau mot de passe (8 caractères minimum)', 'motdepasse')
            ->expectsQuestion('Confirmez le mot de passe', 'motdepasse')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('motdepasse', $twin->fresh()->password));
        $this->assertFalse(Hash::check('motdepasse', $this->dispatcher->fresh()->password));

        $this->artisan('user:password', ['login' => 'inconnu@exemple.ci'])->expectsOutputToContain('Aucun compte')->assertFailed();
    }

    public function test_demo_accounts_are_listed_then_suspended_except_super_admins(): void
    {
        $root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null, 'password' => 'password']);
        $this->admin->update(['password' => 'password']);
        $this->admin->createToken('web');
        $this->dispatcher->update(['password' => 'UnVraiMotDePasse']);

        // Liste seulement
        $this->artisan('app:demo-accounts')
            ->expectsOutputToContain($this->admin->name)
            ->expectsOutputToContain('1 super administrateur(s)')
            ->assertFailed();
        $this->assertSame(UserStatus::Active, $this->admin->fresh()->status);

        $this->artisan('app:demo-accounts --suspend')->assertFailed();

        $this->assertSame(UserStatus::Suspended, $this->admin->fresh()->status);
        $this->assertSame(0, $this->admin->tokens()->count());
        $this->assertSame(UserStatus::Active, $this->dispatcher->fresh()->status);
        $this->assertSame(UserStatus::Active, $root->fresh()->status);
    }
}
