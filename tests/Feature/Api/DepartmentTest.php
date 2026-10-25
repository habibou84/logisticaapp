<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\BillingPlan;
use App\Models\Department;
use App\Models\Order;
use App\Models\User;
use App\Services\Departments\Departments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Départements des clients : fiche, comptes rattachés, département sur la course, courrier interne,
 * autre site, quotas.
 */
class DepartmentTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private BillingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo('2026-11-10 10:00:00');
        foreach ([$this->cocody, $this->yopougon, $this->plateau] as $zone) {
            $zone->update(['in_forfait' => true]);
        }
        Sanctum::actingAs($this->admin);
        $id = $this->postJson("/api/v1/merchants/{$this->merchant->id}/billing-plans", [
            'formula' => 'distribution_contract', 'monthly_fee' => 50000, 'included_quantity' => 100, 'overage_price' => 800,
            'delivery_delay' => '48h', 'cheque_price' => 1500, 'extra_kg_price' => 300, 'starts_on' => '2026-11-01',
        ])->assertCreated()->json('data.id');
        $this->plan = BillingPlan::findOrFail($id);
        $this->merchant->syncActivities([$this->plan->activity]);
    }

    private function department(array $data): Department
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson("/api/v1/merchants/{$this->merchant->id}/departments", $data)->assertCreated()->json('data.id');

        return Department::findOrFail($id);
    }

    private function staff(array $departments): User
    {
        $user = $this->userWithRole(Role::MerchantStaff, ['merchant_id' => $this->merchant->id, 'name' => 'Employé Finance']);
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/merchants/{$this->merchant->id}/users/{$user->id}/departments", ['department_ids' => array_map(fn ($d) => $d->id, $departments)])->assertOk();
        app(Departments::class)->forget();

        return $user;
    }

    private function order(User $actor, array $data = []): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->postJson('/api/v1/orders', [
            'recipient_name' => 'Jean Kouadio', 'recipient_phone' => '0707070707', 'delivery_zone_id' => $this->yopougon->id,
            'delivery_address' => 'Siporex', 'shipment_type' => 'pli', 'activity' => 'distribution', ...$data,
        ]);
    }

    public function test_the_team_manages_departments_and_the_client_sees_them(): void
    {
        $finance = $this->department(['name' => 'Finance', 'code' => 'fin', 'manager_name' => 'Mme Koné', 'phone' => '0102030405', 'location' => 'Bâtiment A, 3e étage', 'cost_center' => 'CC-100', 'monthly_quota' => 50]);
        $marketing = $this->department(['name' => 'Marketing', 'code' => 'MKT']);
        // Premier département : principal ; un seul principal
        $this->assertSame(['FIN', true, false], [$finance->code, $finance->is_main, $marketing->is_main]);
        $this->putJson("/api/v1/departments/{$marketing->id}", ['is_main' => true])->assertOk()->assertJsonPath('data.is_main', true);
        $this->assertFalse($finance->fresh()->is_main);
        $this->putJson("/api/v1/departments/{$marketing->id}", ['is_main' => false])->assertUnprocessable();

        $list = $this->getJson("/api/v1/merchants/{$this->merchant->id}/departments")->assertOk();
        $this->assertSame(['Marketing', 'Finance'], array_column($list->json('data'), 'name'));
        $this->assertSame(['quota' => 50, 'used' => 0, 'percent' => 0], $list->json('data.1.usage'));
        $this->assertTrue($list->json('can_manage'));

        // Le gérant du client les voit et peut les gérer (réglage), pas un employé
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/merchant/departments')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('can_manage', true)->assertJsonPath('data.0.notes', null);
        $this->postJson("/api/v1/merchants/{$this->merchant->id}/departments", ['name' => 'Gestion'])->assertCreated();
        $staff = $this->staff([$finance]);
        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/merchant/departments')->assertOk()->assertJsonPath('can_manage', false)->assertJsonPath('mine', [$finance->id]);
        $this->postJson("/api/v1/merchants/{$this->merchant->id}/departments", ['name' => 'X'])->assertForbidden();

        // L'administrateur retire la main au client
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/merchants/{$this->merchant->id}/department-settings", ['department_settings' => ['client_manages' => false]])->assertOk();
        Sanctum::actingAs($this->merchantUser);
        $this->postJson("/api/v1/merchants/{$this->merchant->id}/departments", ['name' => 'Y'])->assertForbidden();

        // Département avec des courses : désactivé, pas supprimé
        $this->order($this->merchantUser, ['department_id' => $finance->id])->assertCreated();
        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/v1/departments/{$finance->id}")->assertOk()->assertJsonPath('data.archived', true);
        $this->assertFalse($finance->fresh()->active);
    }

    public function test_each_course_says_which_department_sends_it(): void
    {
        $finance = $this->department(['name' => 'Finance', 'location' => 'Bâtiment A, 3e étage', 'manager_name' => 'Mme Koné', 'phone' => '0102030405']);
        $marketing = $this->department(['name' => 'Marketing']);
        // Autre site : son adresse de ramassage
        $agency = $this->department(['name' => 'Agence Plateau', 'pickup_zone_id' => $this->plateau->id, 'pickup_address' => 'Rue du Commerce', 'phone' => '0102030406']);

        // Département obligatoire (réglage par défaut)
        $this->order($this->merchantUser)->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $order = Order::find($this->order($this->merchantUser, ['department_id' => $finance->id, 'requested_by' => 'M. Yao'])->assertCreated()->json('data.id'));
        $this->assertSame([$finance->id, 'M. Yao', $this->cocody->id], [$order->department_id, $order->requested_by, $order->pickup_zone_id]);
        $this->assertStringContainsString('Bâtiment A, 3e étage', $order->pickup_landmark);
        $this->assertSame('Mme Koné (Finance)', $order->pickup_contact_name);
        $this->getJson("/api/v1/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.department.name', 'Finance')
            ->assertJsonPath('data.requested_by', 'M. Yao')
            ->assertJsonPath('data.department_proof.handover_name', 'optional');

        $remote = Order::find($this->order($this->merchantUser, ['department_id' => $agency->id])->assertCreated()->json('data.id'));
        $this->assertSame([$this->plateau->id, 'Rue du Commerce'], [$remote->pickup_zone_id, $remote->pickup_address]);

        // Courrier interne : vers un autre département, sans téléphone ni adresse à saisir
        $internal = $this->order($this->merchantUser, ['department_id' => $finance->id, 'to_department_id' => $agency->id, 'recipient_phone' => null, 'delivery_zone_id' => null, 'recipient_name' => null, 'delivery_address' => null])
            ->assertCreated()->assertJsonPath('data.to_department.name', 'Agence Plateau');
        $this->assertSame([$this->plateau->id, 'Rue du Commerce'], [$internal->json('data.delivery.zone_id'), $internal->json('data.delivery.address')]);
        $this->order($this->merchantUser, ['department_id' => $finance->id, 'to_department_id' => $finance->id])->assertUnprocessable();

        // « Demandé par » obligatoire, puis pas demandé du tout
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/merchants/{$this->merchant->id}/department-settings", ['department_settings' => ['requested_by' => 'required', 'required' => false]])->assertOk();
        $this->order($this->merchantUser, ['department_id' => $marketing->id])->assertUnprocessable()->assertJsonValidationErrors('requested_by');
        // Département facultatif : course sans département
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/merchants/{$this->merchant->id}/department-settings", ['department_settings' => ['requested_by' => 'off', 'required' => false]])->assertOk();
        $this->order($this->merchantUser)->assertCreated()->assertJsonPath('data.department', null);
    }

    public function test_employees_order_and_see_only_for_their_departments(): void
    {
        $finance = $this->department(['name' => 'Finance']);
        $marketing = $this->department(['name' => 'Marketing']);
        $staff = $this->staff([$finance]);

        // Son seul département est choisi tout seul ; pas celui d'un autre
        $mine = $this->order($staff)->assertCreated()->assertJsonPath('data.department.id', $finance->id)->json('data.id');
        $this->order($staff, ['department_id' => $marketing->id])->assertUnprocessable()->assertJsonFragment(['message' => 'Vous ne pouvez commander que pour votre département.']);
        $other = $this->order($this->merchantUser, ['department_id' => $marketing->id])->assertCreated()->json('data.id');
        // Courrier interne reçu par la Finance : visible
        $incoming = $this->order($this->merchantUser, ['department_id' => $marketing->id, 'to_department_id' => $finance->id])->assertCreated()->json('data.id');

        Sanctum::actingAs($staff);
        $ids = collect($this->getJson('/api/v1/orders')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$mine, $incoming], $ids);
        $this->getJson("/api/v1/orders/{$other}")->assertForbidden();

        // Réglage : les employés voient tout le client
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/merchants/{$this->merchant->id}/department-settings", ['department_settings' => ['staff_own_only' => false]])->assertOk();
        Sanctum::actingAs($staff);
        $this->getJson("/api/v1/orders/{$other}")->assertOk();
    }

    public function test_department_quotas_warn_then_block_if_wanted(): void
    {
        $finance = $this->department(['name' => 'Finance', 'monthly_quota' => 5]);
        $staff = $this->staff([$finance]);

        // 4 plis : 80 % → avertissement au gérant et aux comptes du département
        $this->order($staff, ['shipment_quantity' => 4])->assertCreated();
        $this->assertSame('2026-11:80', $finance->fresh()->quota_alert);
        $this->assertSame(1, $staff->notifications()->count());
        $this->assertSame(1, $this->merchantUser->notifications()->count());

        // Courses refusées au-delà du quota (réglage), l'agence peut passer outre
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/merchants/{$this->merchant->id}/department-settings", ['department_settings' => ['quota_block' => true]])->assertOk();
        $this->order($staff, ['shipment_quantity' => 2])->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->order($staff)->assertCreated();
        $this->assertSame('2026-11:100', $finance->fresh()->quota_alert);
        $this->order($this->admin, ['merchant_id' => $this->merchant->id, 'department_id' => $finance->id])->assertCreated();
        $this->assertSame(['quota' => 5, 'used' => 6, 'percent' => 120], app(Departments::class)->usage($finance->fresh()));
    }

    public function test_who_hands_over_and_who_receives_is_noted_and_slips_are_signed(): void
    {
        Storage::fake('local');
        $finance = $this->department(['name' => 'Finance', 'location' => 'Bâtiment A']);
        $agency = $this->department(['name' => 'Agence Plateau', 'pickup_zone_id' => $this->plateau->id, 'pickup_address' => 'Rue du Commerce']);
        $financeStaff = $this->staff([$finance]);
        $agencyStaff = $this->userWithRole(Role::MerchantStaff, ['merchant_id' => $this->merchant->id]);
        $agencyStaff->departments()->sync([$agency->id]);
        app(Departments::class)->forget();
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/merchants/{$this->merchant->id}/department-settings", ['department_settings' => ['handover_name' => 'required', 'handover_signature' => 'required', 'received_role' => 'required']])->assertOk();

        $order = Order::find($this->order($financeStaff, ['to_department_id' => $agency->id, 'recipient_phone' => null, 'delivery_zone_id' => null])->assertCreated()->json('data.id'));
        $order->forceFill(['status' => OrderStatus::PickupInProgress])->save();
        $signature = 'data:image/png;base64,'.base64_encode(UploadedFile::fake()->image('s.png', 20, 10)->getContent());

        // Au ramassage : qui remet, sa signature
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertUnprocessable()->assertJsonValidationErrors('handed_by_name');
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up', 'handed_by_name' => 'M. Yao'])->assertUnprocessable()->assertJsonValidationErrors('signature');
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up', 'handed_by_name' => 'M. Yao', 'handed_by_role' => 'Assistant', 'signature' => $signature])->assertOk()
            ->assertJsonPath('data.handed_by_name', 'M. Yao')->assertJsonPath('data.handed_by_role', 'Assistant');
        $this->assertSame(['signature_pickup'], $order->attachments()->pluck('type')->all());
        // Comptes du client prévenus : ceux des départements concernés seulement
        $this->assertStringContainsString('Remis par M. Yao (Assistant)', $financeStaff->notifications()->first()->data['body']);
        $this->assertSame(1, $agencyStaff->notifications()->count());
        $other = $this->staff([$this->department(['name' => 'Marketing'])]);

        // À la remise : fonction de la personne qui reçoit
        $order->forceFill(['status' => OrderStatus::OutForDelivery])->save();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered', 'received_by_name' => 'Mme Bamba'])->assertUnprocessable()->assertJsonValidationErrors('received_by_role');
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered', 'received_by_name' => 'Mme Bamba', 'received_by_role' => 'Secrétaire'])->assertOk()
            ->assertJsonPath('data.received_by_role', 'Secrétaire');
        $this->assertStringContainsString('Reçu par Mme Bamba (Secrétaire)', $agencyStaff->notifications()->get()->pluck('data.body')->implode(' | '));
        $this->assertSame(0, $other->notifications()->count());

        // Bordereaux du jour : remis par la Finance, reçu par l'agence
        Sanctum::actingAs($financeStaff);
        $slip = $this->getJson("/api/v1/departments/{$finance->id}/slip?kind=pickup")->assertOk();
        $this->assertSame(['M. Yao (Assistant)', 'Agence Plateau'], [$slip->json('data.orders.0.handed_by'), $slip->json('data.orders.0.recipient')]);
        $this->getJson("/api/v1/departments/{$agency->id}/slip?kind=delivery")->assertForbidden();
        $this->postJson("/api/v1/departments/{$finance->id}/slips", ['day' => now()->toDateString(), 'kind' => 'pickup', 'signed_by_name' => 'Mme Koné', 'signature' => $signature])->assertCreated()->assertJsonPath('data.orders', 1);
        $signed = $this->getJson("/api/v1/departments/{$finance->id}/slip?kind=pickup")->assertOk()->json('data.slips.0');
        $this->assertSame([[$order->id], 'Mme Koné'], [$signed['order_ids'], $signed['signed_by_name']]);
        $this->get($signed['signature_url'])->assertOk();

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/v1/departments/{$agency->id}/slip?kind=delivery")->assertOk()->assertJsonPath('data.orders.0.received_by', 'Mme Bamba (Secrétaire)');
    }
}
