<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
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
        $this->admin->update(['phone' => '0700000001']);
        $this->admin->createToken('web');

        $this->artisan('user:password', ['login' => '07 00 00 00 01'])
            ->expectsQuestion('Nouveau mot de passe (8 caractères minimum)', 'court')
            ->expectsOutputToContain('au moins 8 caractères')
            ->assertFailed();

        $this->artisan('user:password', ['login' => '0700000001'])
            ->expectsQuestion('Nouveau mot de passe (8 caractères minimum)', 'UnTresBonMotDePasse')
            ->expectsQuestion('Confirmez le mot de passe', 'UnAutre')
            ->expectsOutputToContain('ne correspondent pas')
            ->assertFailed();

        $this->artisan('user:password', ['login' => '0700000001'])
            ->expectsQuestion('Nouveau mot de passe (8 caractères minimum)', 'UnTresBonMotDePasse')
            ->expectsQuestion('Confirmez le mot de passe', 'UnTresBonMotDePasse')
            ->expectsOutputToContain("Mot de passe changé pour {$this->admin->name}")
            ->assertSuccessful();

        $this->assertTrue(Hash::check('UnTresBonMotDePasse', $this->admin->fresh()->password));
        $this->assertSame(0, $this->admin->tokens()->count());

        $this->artisan('user:password', ['login' => 'inconnu@exemple.ci'])->expectsOutputToContain('Aucun compte')->assertFailed();
    }

    public function test_demo_accounts_are_listed_then_suspended(): void
    {
        $this->admin->update(['password' => 'password']);
        $this->admin->createToken('web');
        $this->dispatcher->update(['password' => 'UnVraiMotDePasse']);

        // Liste seulement
        $this->artisan('app:demo-accounts')
            ->expectsOutputToContain($this->admin->name)
            ->assertFailed();
        $this->assertSame(UserStatus::Active, $this->admin->fresh()->status);

        $this->artisan('app:demo-accounts --suspend')->assertSuccessful();

        $this->assertSame(UserStatus::Suspended, $this->admin->fresh()->status);
        $this->assertSame(0, $this->admin->tokens()->count());
        $this->assertSame(UserStatus::Active, $this->dispatcher->fresh()->status);
    }
}
