<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\BillingPlan;
use App\Models\Department;
use App\Models\User;
use App\Notifications\ClientBillingAlert;
use App\Services\Departments\Departments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Comptes d'un client (propriétaire, gestionnaire, agent, lecteur) et validation des demandes par le chef de service.
 */
class ClientRolesTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo('2026-11-10 10:00:00');
        foreach ([$this->cocody, $this->yopougon] as $zone) {
            $zone->update(['in_forfait' => true]);
        }
        Sanctum::actingAs($this->admin);
        $id = $this->postJson("/api/v1/merchants/{$this->merchant->id}/billing-plans", [
            'formula' => 'distribution_contract', 'monthly_fee' => 50000, 'included_quantity' => 100, 'overage_price' => 800,
            'delivery_delay' => '48h', 'cheque_price' => 1500, 'extra_kg_price' => 300, 'starts_on' => '2026-11-01',
        ])->assertCreated()->json('data.id');
        $this->merchant->syncActivities([BillingPlan::findOrFail($id)->activity]);
    }

    private function account(Role $role, array $attributes = []): User
    {
        return $this->userWithRole($role, ['merchant_id' => $this->merchant->id, ...$attributes]);
    }

    private function order(User $actor, array $data = []): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->postJson('/api/v1/orders', [
            'recipient_name' => 'Jean Kouadio', 'recipient_phone' => '0707070707', 'delivery_zone_id' => $this->yopougon->id,
            'delivery_address' => 'Siporex', 'shipment_type' => 'pli', 'activity' => 'distribution', ...$data,
        ]);
    }

    public function test_the_viewer_follows_without_acting(): void
    {
        $order = $this->createOrder(['activity' => 'distribution', 'shipment_type' => 'pli']);
        $viewer = $this->account(Role::MerchantViewer);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('data.0.id', $order->id);
        $this->order($viewer)->assertForbidden();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'cancelled'])->assertForbidden();
        $this->postJson("/api/v1/orders/{$order->id}/return-request")->assertForbidden();
    }

    public function test_client_accounts_are_managed_by_the_owner_the_manager_and_the_team(): void
    {
        $payload = ['name' => 'Awa', 'phone' => '0501020304', 'password' => 'password123'];

        // Le gestionnaire : agents, lecteurs, gestionnaires ; jamais de propriétaire, ni toucher au propriétaire
        $manager = $this->account(Role::MerchantManager);
        Sanctum::actingAs($manager);
        $list = $this->getJson("/api/v1/merchants/{$this->merchant->id}/users")->assertOk();
        $this->assertNotContains('merchant_owner', $list->json('assignable'));
        $this->postJson("/api/v1/merchants/{$this->merchant->id}/users", [...$payload, 'role' => 'merchant_owner'])->assertJsonValidationErrors('role');
        $agent = $this->postJson("/api/v1/merchants/{$this->merchant->id}/users", [...$payload, 'role' => 'merchant_staff'])
            ->assertCreated()->assertJsonPath('data.role_label', 'Agent du client')->json('data');
        $this->patchJson("/api/v1/merchants/{$this->merchant->id}/users/{$agent['id']}", ['role' => 'merchant_viewer'])->assertOk()->assertJsonPath('data.role', 'merchant_viewer');
        $this->patchJson("/api/v1/merchants/{$this->merchant->id}/users/{$this->merchantUser->id}", ['status' => 'suspended'])->assertForbidden();

        // Un agent ne gère pas les comptes ; un autre client non plus
        Sanctum::actingAs(User::find($agent['id']));
        $this->getJson("/api/v1/merchants/{$this->merchant->id}/users")->assertForbidden();

        // Le propriétaire : tous les rôles, sauf se retirer s'il est le dernier
        Sanctum::actingAs($this->merchantUser);
        $this->patchJson("/api/v1/merchants/{$this->merchant->id}/users/{$manager->id}", ['role' => 'merchant_owner'])->assertOk();
        Sanctum::actingAs($manager->fresh());
        $this->patchJson("/api/v1/merchants/{$this->merchant->id}/users/{$this->merchantUser->id}", ['status' => 'suspended'])->assertOk();
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/merchants/{$this->merchant->id}/users/{$manager->id}", ['role' => 'merchant_staff'])->assertJsonValidationErrors('role');

        // L'équipe (gérer les clients) : tous les rôles
        $this->postJson("/api/v1/merchants/{$this->merchant->id}/users", [...$payload, 'phone' => '0501020399', 'role' => 'merchant_owner'])->assertCreated();
    }

    public function test_the_manager_sees_every_department(): void
    {
        Sanctum::actingAs($this->admin);
        $finance = Department::findOrFail($this->postJson("/api/v1/merchants/{$this->merchant->id}/departments", ['name' => 'Finance'])->json('data.id'));
        $manager = $this->account(Role::MerchantManager);
        $manager->departments()->sync([$finance->id]);

        $this->assertNull(app(Departments::class)->scopeFor($manager->fresh()));
    }

    public function test_requests_wait_for_the_head_of_department(): void
    {
        Notification::fake();
        $this->company->update(['auto_confirm_orders' => true, 'department_settings' => ['head_approval' => true]]);
        Sanctum::actingAs($this->admin);
        $finance = Department::findOrFail($this->postJson("/api/v1/merchants/{$this->merchant->id}/departments", ['name' => 'Finance'])->json('data.id'));
        $agent = $this->account(Role::MerchantStaff, ['name' => 'Agent Finance']);
        $head = $this->account(Role::MerchantStaff, ['name' => 'Chef Finance']);
        $this->putJson("/api/v1/merchants/{$this->merchant->id}/users/{$agent->id}/departments", ['department_ids' => [$finance->id]])->assertOk();
        $this->putJson("/api/v1/merchants/{$this->merchant->id}/users/{$head->id}/departments", ['department_ids' => [$finance->id], 'head_department_ids' => [$finance->id]])
            ->assertOk()->assertJsonPath('data.head_department_ids', [$finance->id]);
        app(Departments::class)->forget();

        // La demande de l'agent attend : pas de validation automatique, le chef est prévenu
        $id = $this->order($agent->fresh(), ['department_id' => $finance->id])->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.approval.awaiting', true)->json('data.id');
        Notification::assertSentTo($head, ClientBillingAlert::class, fn ($n) => $n->kind === 'order_approval');

        // L'agence ne peut ni la valider ni la voir « à valider » ; l'agent ne la valide pas lui-même
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$id}/status", ['status' => 'confirmed'])->assertStatus(422);
        $this->assertNotContains($id, collect($this->getJson('/api/v1/orders?queue=to_confirm')->json('data'))->pluck('id')->all());
        Sanctum::actingAs($agent);
        $this->postJson("/api/v1/orders/{$id}/approval", ['approve' => true])->assertForbidden();

        // Le chef la voit à valider, la valide : elle part (validation automatique de l'entreprise)
        Sanctum::actingAs($head);
        $this->assertContains($id, collect($this->getJson('/api/v1/orders?queue=to_approve')->json('data'))->pluck('id')->all());
        $this->getJson("/api/v1/orders/{$id}")->assertJsonPath('data.approval.can_decide', true);
        $this->postJson("/api/v1/orders/{$id}/approval", ['approve' => true])->assertOk()
            ->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.approval.approved_by', 'Chef Finance');

        // Une autre, refusée : annulée avec le motif
        $other = $this->order($agent, ['department_id' => $finance->id])->json('data.id');
        Sanctum::actingAs($head);
        $this->postJson("/api/v1/orders/{$other}/approval", ['approve' => false, 'reason' => 'Pas urgent'])->assertOk()->assertJsonPath('data.status', 'cancelled');

        // Le chef, le gérant : leurs propres demandes partent directement
        $this->assertSame(OrderStatus::Confirmed->value, $this->order($head, ['department_id' => $finance->id])->json('data.status'));
    }
}
