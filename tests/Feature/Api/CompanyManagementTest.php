<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyManagementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = RolesAndPermissionsSeeder::class;

    public function test_super_admin_creates_a_company_with_its_first_admin(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $response = $this->postJson('/api/v1/companies', [
            'name' => 'Rapide Livraison Abidjan',
            'phone' => '27 22 00 00 00',
            'admin' => [
                'name' => 'Aminata Diallo',
                'phone' => '0102030405',
                'password' => 'motdepasse',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'rapide-livraison-abidjan')
            ->assertJsonPath('data.phone', '+2252722000000')
            ->assertJsonPath('data.users_count', 1);

        $admin = User::where('phone', '+2250102030405')->firstOrFail();
        $this->assertSame($response->json('data.id'), $admin->company_id);
        $this->assertTrue($admin->hasRole(Role::Admin->value));
    }

    public function test_slugs_are_made_unique(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        Company::factory()->create(['slug' => 'express']);

        $this->postJson('/api/v1/companies', ['name' => 'Express'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'express-2');
    }

    public function test_company_admin_cannot_list_or_create_companies(): void
    {
        Sanctum::actingAs(User::factory()->withRole(Role::Admin)->create());

        $this->getJson('/api/v1/companies')->assertForbidden();
        $this->postJson('/api/v1/companies', ['name' => 'Concurrent'])->assertForbidden();
    }

    public function test_company_admin_updates_their_own_company_settings(): void
    {
        $admin = User::factory()->withRole(Role::Admin)->create();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/companies/{$admin->company_id}", ['default_max_attempts' => 2])
            ->assertOk()
            ->assertJsonPath('data.default_max_attempts', 2);
    }

    public function test_company_admin_cannot_suspend_their_company_or_touch_another_one(): void
    {
        $admin = User::factory()->withRole(Role::Admin)->create();
        $other = Company::factory()->create();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/companies/{$admin->company_id}", ['status' => 'suspended'])->assertForbidden();
        $this->getJson("/api/v1/companies/{$other->id}")->assertForbidden();
        $this->patchJson("/api/v1/companies/{$other->id}", ['name' => 'X'])->assertForbidden();
    }

    public function test_super_admin_can_suspend_a_company(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $company = Company::factory()->create();

        $this->patchJson("/api/v1/companies/{$company->id}", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');
    }
}
