<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = RolesAndPermissionsSeeder::class;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->admin = User::factory()->for($this->company)->withRole(Role::Admin)->create();
    }

    private function courierPayload(array $overrides = []): array
    {
        return [
            'name' => 'Koffi Kouassi',
            'phone' => '05 05 05 05 05',
            'password' => 'motdepasse',
            'role' => 'courier',
            ...$overrides,
        ];
    }

    public function test_admin_creates_a_courier_in_their_own_company(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/users', $this->courierPayload())
            ->assertCreated()
            ->assertJsonPath('data.phone', '+2250505050505')
            ->assertJsonPath('data.role', 'courier')
            ->assertJsonPath('data.company_id', $this->company->id);
    }

    public function test_admin_cannot_choose_another_company(): void
    {
        Sanctum::actingAs($this->admin);
        $other = Company::factory()->create();

        $this->postJson('/api/v1/users', $this->courierPayload(['company_id' => $other->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');
    }

    public function test_admin_cannot_create_a_super_admin(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/users', $this->courierPayload(['role' => 'super_admin']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_phone_numbers_are_unique_within_the_company_whatever_the_format(): void
    {
        Sanctum::actingAs($this->admin);
        User::factory()->for($this->company)->create(['phone' => '+2250505050505']);

        $this->postJson('/api/v1/users', $this->courierPayload(['phone' => '0505050505']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.phone.0', 'Ce téléphone est déjà utilisé.');

        // Le même numéro dans une autre entreprise : accepté (livreur de deux entreprises)
        User::factory()->create(['phone' => '+2250707070707']);
        $this->postJson('/api/v1/users', $this->courierPayload(['phone' => '0707070707']))->assertCreated();
    }

    public function test_dispatcher_can_list_users_but_not_create_them(): void
    {
        $dispatcher = User::factory()->for($this->company)->withRole(Role::Dispatcher)->create();
        Sanctum::actingAs($dispatcher);

        $this->getJson('/api/v1/users')->assertOk();
        $this->postJson('/api/v1/users', $this->courierPayload())
            ->assertForbidden()
            ->assertExactJson(['message' => 'Action non autorisée.']);
    }

    public function test_courier_cannot_list_users(): void
    {
        Sanctum::actingAs(User::factory()->for($this->company)->withRole(Role::Courier)->create());

        $this->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_listing_is_limited_to_the_admin_company(): void
    {
        User::factory()->for($this->company)->withRole(Role::Courier)->create(['name' => 'Interne']);
        User::factory()->withRole(Role::Courier)->create(['name' => 'Externe']);
        Sanctum::actingAs($this->admin);

        $names = collect($this->getJson('/api/v1/users')->assertOk()->json('data'))->pluck('name');

        $this->assertContains('Interne', $names);
        $this->assertNotContains('Externe', $names);
    }

    public function test_listing_can_be_filtered_by_role_and_search(): void
    {
        User::factory()->for($this->company)->withRole(Role::Courier)->create(['name' => 'Awa Traoré']);
        User::factory()->for($this->company)->withRole(Role::Courier)->create(['name' => 'Moussa Koné']);
        User::factory()->for($this->company)->withRole(Role::Cashier)->create(['name' => 'Awa Caisse']);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/users?role=courier&search=awa')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Awa Traoré');
    }

    public function test_admin_cannot_view_or_edit_a_user_of_another_company(): void
    {
        $outsider = User::factory()->withRole(Role::Courier)->create();
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/v1/users/{$outsider->id}")->assertForbidden();
        $this->patchJson("/api/v1/users/{$outsider->id}", ['name' => 'Piraté'])->assertForbidden();
        $this->deleteJson("/api/v1/users/{$outsider->id}")->assertForbidden();
    }

    public function test_suspending_a_user_revokes_their_tokens(): void
    {
        $courier = User::factory()->for($this->company)->withRole(Role::Courier)->create();
        $courier->createToken('telephone');
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/v1/users/{$courier->id}", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->assertSame(0, $courier->tokens()->count());
    }

    public function test_admin_can_change_a_user_role(): void
    {
        $courier = User::factory()->for($this->company)->withRole(Role::Courier)->create();
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/v1/users/{$courier->id}", ['role' => 'dispatcher'])
            ->assertOk()
            ->assertJsonPath('data.role', 'dispatcher');

        $this->assertSame(['dispatcher'], $courier->fresh()->getRoleNames()->all());
    }

    public function test_admin_cannot_change_their_own_role_or_delete_themselves(): void
    {
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/v1/users/{$this->admin->id}", ['status' => 'suspended'])->assertForbidden();
        $this->deleteJson("/api/v1/users/{$this->admin->id}")->assertForbidden();
    }

    public function test_deleting_a_user_soft_deletes_it(): void
    {
        $courier = User::factory()->for($this->company)->withRole(Role::Courier)->create();
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/v1/users/{$courier->id}")->assertNoContent();

        $this->assertSoftDeleted($courier);
    }

    public function test_assignable_roles_endpoint(): void
    {
        Sanctum::actingAs($this->admin);

        $roles = collect($this->getJson('/api/v1/roles')->assertOk()->json('data'))->pluck('value');

        $this->assertContains('courier', $roles);
        $this->assertNotContains('super_admin', $roles);
    }

    public function test_super_admin_creates_users_in_any_company_and_must_choose_one(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson('/api/v1/users', $this->courierPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');

        $this->postJson('/api/v1/users', $this->courierPayload(['company_id' => $this->company->id]))
            ->assertCreated()
            ->assertJsonPath('data.company_id', $this->company->id);
    }
}
