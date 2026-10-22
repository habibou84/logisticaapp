<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\PricingGrid;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Première mise en service : l'entreprise et son administrateur, une seule fois.
 */
class InstallCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_creates_the_company_and_its_first_admin(): void
    {
        $this->artisan('app:install', ['--name' => 'Logistica Delivery', '--admin-name' => 'Aminata Diallo', '--admin-phone' => '01 02 03 04 05'])
            ->expectsQuestion('E-mail de l\'administrateur (facultatif)', '')
            ->expectsQuestion('Mot de passe de l\'administrateur (8 caractères minimum)', 'motdepasse')
            ->expectsOutputToContain('Entreprise « Logistica Delivery » créée.')
            ->assertSuccessful();

        $company = Company::sole();
        $this->assertSame('logistica-delivery', $company->slug);
        $this->assertSame(13, Zone::forCompany($company->id)->count());
        $this->assertTrue(PricingGrid::forCompany($company->id)->where('is_default', true)->exists());

        $admin = User::where('phone', '+2250102030405')->sole();
        $this->assertSame($company->id, $admin->company_id);
        $this->assertTrue($admin->hasRole(Role::Admin->value));
        $this->assertTrue(Hash::check('motdepasse', $admin->password));
    }

    public function test_install_refuses_a_second_company_and_bad_input(): void
    {
        $this->artisan('app:install', ['--name' => 'Logistica', '--admin-name' => 'A', '--admin-phone' => '123', '--admin-email' => 'a@b.ci'])
            ->expectsQuestion('Mot de passe de l\'administrateur (8 caractères minimum)', 'court')
            ->assertFailed();
        $this->assertSame(0, Company::count());

        Company::factory()->create(['name' => 'Déjà là']);
        $this->artisan('app:install')->expectsOutputToContain('L\'entreprise est déjà créée : Déjà là.')->assertFailed();
        $this->artisan('app:install --skip-if-installed')->expectsOutputToContain('Entreprise déjà créée : Déjà là.')->assertSuccessful();
    }
}
