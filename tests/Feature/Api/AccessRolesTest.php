<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\AccessRole;
use App\Models\User;
use App\Services\Access\Access;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Permissions par métier et rôles sur mesure : les rôles intégrés gardent ce qu'ils faisaient, les réglages se
 * contrôlent champ par champ, personne ne donne un droit qu'il n'a pas, périmètre par zones et dépôts.
 */
class AccessRolesTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    /** Compte de l'équipe avec un rôle sur mesure */
    private function customUser(array $permissions, array $role = [], array $attributes = []): User
    {
        $access = AccessRole::create(['name' => 'custom_'.uniqid(), 'guard_name' => 'web', 'is_custom' => true,
            'company_id' => $this->company->id, 'label' => 'Rôle '.uniqid(), ...$role]);
        $access->syncPermissions($permissions);

        $user = User::factory()->create(['company_id' => $this->company->id, ...$attributes]);
        $user->assignRole($access->name);

        return $user->fresh();
    }

    public function test_built_in_roles_keep_what_they_could_do(): void
    {
        // Le dispatcher garde planning, véhicules et terrain (nés de « dispatch »), pas la notation ni WhatsApp
        $this->assertTrue($this->dispatcher->can('planning.manage'));
        $this->assertTrue($this->dispatcher->can('fleet.manage'));
        $this->assertTrue($this->dispatcher->can('orders.cancel'));
        $this->assertFalse($this->dispatcher->can('ratings.manage'));
        $this->assertFalse($this->dispatcher->can('messaging.manage'));
        $this->assertContains('invoices.void', array_map(fn ($p) => $p->value, Role::Cashier->permissions()));

        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/vehicles')->assertOk();
        $this->getJson('/api/v1/planning')->assertOk();
        $this->getJson('/api/v1/whatsapp/settings')->assertForbidden();
        $this->postJson('/api/v1/zones', ['name' => 'Bingerville'])->assertForbidden();
    }

    public function test_settings_are_checked_field_by_field(): void
    {
        $fleet = $this->customUser(['fleet.manage']);
        Sanctum::actingAs($fleet);
        $url = "/api/v1/companies/{$this->company->id}";

        $this->patchJson($url, ['vehicle_settings' => ['check_mode' => 'weekly']])->assertOk();
        // Un réglage général qui change : refusé ; renvoyé tel quel (page qui enregistre tout) : accepté
        $this->patchJson($url, ['name' => 'Autre nom'])->assertForbidden();
        $this->patchJson($url, ['name' => $this->company->name, 'vehicle_settings' => ['check_mode' => 'daily']])->assertOk();
        $this->postJson('/api/v1/company/logo')->assertForbidden();
        $this->getJson('/api/v1/vehicles')->assertOk();
        $this->getJson('/api/v1/planning')->assertForbidden();
    }

    public function test_cancelling_a_course_needs_its_own_right(): void
    {
        $order = $this->createOrder();
        $this->dispatcher->forceFill(['denied_permissions' => ['orders.cancel']])->save();

        try {
            app(OrderWorkflow::class)->transition($this->dispatcher->fresh(), $order, OrderStatus::Cancelled);
            $this->fail('Annulation acceptée sans le droit.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('annuler', $e->getMessage());
        }

        // Le client annule toujours la sienne avant ramassage
        $this->assertSame(OrderStatus::Cancelled, app(OrderWorkflow::class)->transition($this->merchantUser, $order, OrderStatus::Cancelled)->status);
    }

    public function test_custom_role_lifecycle(): void
    {
        Sanctum::actingAs($this->admin);
        $catalog = $this->getJson('/api/v1/roles')->assertOk();
        $template = collect($catalog->json('templates'))->firstWhere('key', 'fleet_manager');
        $this->assertContains('fleet.manage', $template['permissions']);
        $this->assertSame('couriers', collect($catalog->json('permissions'))->firstWhere('value', 'fleet.manage')['domain']);

        $role = $this->postJson('/api/v1/roles', ['label' => 'Responsable flotte', 'template' => 'fleet_manager', 'permissions' => $template['permissions']])
            ->assertCreated()->json('data');
        $this->assertTrue($role['custom']);
        $this->postJson('/api/v1/roles', ['label' => 'Responsable flotte', 'permissions' => []])->assertJsonValidationErrors('label');

        $user = $this->postJson('/api/v1/users', ['name' => 'Moussa', 'phone' => '0701020304', 'password' => 'password123', 'role' => $role['value']])
            ->assertCreated()->assertJsonPath('data.role_label', 'Responsable flotte')->assertJsonPath('data.space', 'staff')->json('data');

        Sanctum::actingAs(User::find($user['id']));
        $this->getJson('/api/v1/vehicles')->assertOk();
        $this->getJson('/api/v1/couriers')->assertOk();
        $this->getJson('/api/v1/planning')->assertForbidden();

        // Le rôle change : ses comptes suivent
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/roles/'.AccessRole::where('name', $role['value'])->value('id'), ['label' => 'Flotte', 'permissions' => [...$template['permissions'], 'planning.manage']])
            ->assertOk()->assertJsonPath('data.label', 'Flotte');
        $this->assertTrue(User::find($user['id'])->can('planning.manage'));

        // Supprimé seulement quand plus personne ne l'a ; les rôles intégrés ne se modifient pas
        $id = AccessRole::where('name', $role['value'])->value('id');
        $this->deleteJson("/api/v1/roles/{$id}")->assertStatus(422);
        $this->patchJson("/api/v1/users/{$user['id']}", ['role' => 'dispatcher'])->assertOk();
        $this->deleteJson("/api/v1/roles/{$id}")->assertNoContent();
        $this->putJson('/api/v1/roles/'.AccessRole::where('name', 'dispatcher')->value('id'), ['label' => 'X', 'permissions' => []])->assertForbidden();
    }

    public function test_nobody_grants_a_right_they_do_not_have(): void
    {
        $manager = $this->customUser(['users.view', 'users.manage', 'orders.view', 'orders.create']);
        Sanctum::actingAs($manager);

        $this->postJson('/api/v1/roles', ['label' => 'Caisse', 'permissions' => ['finance.manage']])->assertJsonValidationErrors('permissions');
        $small = $this->postJson('/api/v1/roles', ['label' => 'Saisie', 'permissions' => ['orders.view', 'orders.create']])->assertCreated()->json('data');

        $payload = ['name' => 'Aya', 'phone' => '0701020399', 'password' => 'password123'];
        // Rôle plus large que soi, ou administrateur : impossible
        $this->postJson('/api/v1/users', [...$payload, 'role' => 'dispatcher'])->assertJsonValidationErrors('role');
        $this->postJson('/api/v1/users', [...$payload, 'role' => 'admin'])->assertJsonValidationErrors('role');
        $this->postJson('/api/v1/users', [...$payload, 'role' => $small['value'], 'extra_permissions' => ['finance.view']])->assertJsonValidationErrors('extra_permissions');
        $aya = $this->postJson('/api/v1/users', [...$payload, 'role' => $small['value']])->assertCreated()->json('data');

        // Un compte plus puissant que soi : intouchable
        $this->patchJson("/api/v1/users/{$this->dispatcher->id}", ['status' => 'suspended'])->assertForbidden();
        $this->deleteJson("/api/v1/users/{$this->admin->id}")->assertForbidden();
        $this->patchJson("/api/v1/users/{$aya['id']}", ['status' => 'suspended'])->assertOk();
    }

    public function test_the_last_active_admin_stays(): void
    {
        $access = app(Access::class);
        $this->assertTrue($access->isLastAdmin($this->admin));

        $other = $this->userWithRole(Role::Admin);
        $this->assertFalse($access->isLastAdmin($this->admin));
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/users/{$other->id}", ['status' => UserStatus::Suspended->value])->assertOk();
        $this->assertTrue($access->isLastAdmin($this->admin->fresh()));

        // Rétabli, puis il rétrograde le premier : celui-ci n'est plus le dernier
        $this->patchJson("/api/v1/users/{$other->id}", ['status' => UserStatus::Active->value])->assertOk();
        Sanctum::actingAs($other->fresh());
        $this->patchJson("/api/v1/users/{$this->admin->id}", ['role' => 'dispatcher'])->assertOk();
        $this->assertTrue($access->isLastAdmin($other->fresh()));
    }

    public function test_perimeter_by_zones_and_hubs(): void
    {
        $this->rule($this->grid, $this->plateau, $this->plateau, 800);
        $cocody = $this->createOrder();
        $plateau = $this->createOrder(['pickup_zone_id' => $this->plateau->id, 'delivery_zone_id' => $this->plateau->id]);

        // Rôle limité à Cocody (et ses quartiers)
        $user = $this->customUser(['orders.view'], ['zone_ids' => [$this->cocody->id]]);
        $this->assertContains($this->angre->id, $user->zoneScope());
        Sanctum::actingAs($user);
        $ids = collect($this->getJson('/api/v1/orders')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($cocody->id));
        $this->assertFalse($ids->contains($plateau->id));
        $this->getJson("/api/v1/orders/{$plateau->id}")->assertNotFound();

        // Le compte a son propre périmètre : il l'emporte sur celui du rôle
        $user->forceFill(['zone_ids' => [$this->plateau->id]])->save();
        Sanctum::actingAs($user->fresh());
        $ids = collect($this->getJson('/api/v1/orders')->assertOk()->json('data'))->pluck('id');
        $this->assertEquals([$plateau->id], $ids->all());

        // L'administrateur voit tout
        $this->assertNull($this->admin->zoneScope());
    }

    public function test_see_as_this_account(): void
    {
        $this->dispatcher->forceFill(['denied_permissions' => ['orders.cancel'], 'activities' => ['mad']])->save();
        $this->dispatcher->givePermissionTo(Permission::FinanceView->value);
        Sanctum::actingAs($this->admin);

        $data = $this->getJson("/api/v1/users/{$this->dispatcher->id}/access")->assertOk()->json('data');
        $this->assertSame('Dispatcher', $data['role_label']);
        $this->assertContains('finance.view', $data['permissions']);
        $this->assertNotContains('orders.cancel', $data['permissions']);
        $this->assertContains('orders.cancel', $data['from_role']);
        $this->assertSame(['mad'], $data['scope']['activities']);
    }
}
