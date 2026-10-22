<?php

namespace Tests\Feature\Api;

use App\Enums\CompanyStatus;
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

    public function test_companies_cannot_be_listed_or_created_from_the_api(): void
    {
        Sanctum::actingAs(User::factory()->withRole(Role::Admin)->create());

        $this->getJson('/api/v1/companies')->assertNotFound();
        $this->postJson('/api/v1/companies', ['name' => 'Concurrent'])->assertNotFound();
    }

    public function test_company_admin_updates_their_own_company_settings(): void
    {
        $admin = User::factory()->withRole(Role::Admin)->create();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/companies/{$admin->company_id}", ['default_max_attempts' => 2])
            ->assertOk()
            ->assertJsonPath('data.default_max_attempts', 2);
    }

    public function test_status_and_slug_are_not_changed_from_the_settings(): void
    {
        $admin = User::factory()->withRole(Role::Admin)->create();
        $slug = $admin->company->slug;
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/companies/{$admin->company_id}", ['status' => 'suspended', 'slug' => 'autre-adresse'])->assertOk();

        $this->assertSame(CompanyStatus::Active, $admin->company->fresh()->status);
        $this->assertSame($slug, $admin->company->fresh()->slug);
    }

    public function test_only_an_admin_of_the_company_changes_its_settings(): void
    {
        $dispatcher = User::factory()->withRole(Role::Dispatcher)->create();
        Sanctum::actingAs($dispatcher);
        $this->patchJson("/api/v1/companies/{$dispatcher->company_id}", ['name' => 'X'])->assertForbidden();

        $admin = User::factory()->withRole(Role::Admin)->create();
        $other = Company::factory()->create();
        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/companies/{$other->id}")->assertForbidden();
        $this->patchJson("/api/v1/companies/{$other->id}", ['name' => 'X'])->assertForbidden();
    }
}
