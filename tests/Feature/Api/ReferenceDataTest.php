<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\PricingGrid;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

class ReferenceDataTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_every_company_user_lists_its_own_zones(): void
    {
        $this->zone('Ailleurs', company: Company::factory()->create());
        Sanctum::actingAs($this->merchantUser);

        $names = collect($this->getJson('/api/v1/zones')->assertOk()->json('data'))->pluck('full_name');

        $this->assertEqualsCanonicalizing(['Cocody', 'Yopougon', 'Plateau', 'Cocody › Angré'], $names->all());
    }

    public function test_only_settings_managers_create_zones(): void
    {
        Sanctum::actingAs($this->dispatcher);
        $this->postJson('/api/v1/zones', ['name' => 'Marcory'])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/zones', ['name' => 'Marcory'])->assertCreated()->assertJsonPath('data.name', 'Marcory');
        $this->postJson('/api/v1/zones', ['name' => 'Marcory'])->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/zones', ['name' => 'Riviera 3', 'parent_id' => $this->angre->id])
            ->assertJsonValidationErrors('parent_id'); // un seul niveau de sous-zone
    }

    public function test_admin_edits_a_zone(): void
    {
        Sanctum::actingAs($this->dispatcher);
        $this->patchJson("/api/v1/zones/{$this->angre->id}", ['name' => 'Angré 8e tranche'])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/zones/{$this->angre->id}", ['name' => 'Angré 8e tranche', 'parent_id' => $this->plateau->id])->assertOk()
            ->assertJsonPath('data.full_name', 'Plateau › Angré 8e tranche');
        $this->patchJson("/api/v1/zones/{$this->angre->id}", ['parent_id' => null])->assertOk()->assertJsonPath('data.parent_id', null);

        // Une commune qui a des quartiers reste une commune
        $this->patchJson("/api/v1/zones/{$this->plateau->id}", ['parent_id' => $this->cocody->id])->assertOk();
        $this->patchJson("/api/v1/zones/{$this->cocody->id}", ['parent_id' => $this->yopougon->id])->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_only_unused_zones_can_be_deleted(): void
    {
        Sanctum::actingAs($this->admin);
        $unused = $this->zone('Bingerville');
        $this->rule($this->grid, $unused, $this->plateau, 2500);
        $this->courierA->zones()->attach($unused->id);

        $this->deleteJson("/api/v1/zones/{$unused->id}")->assertNoContent();
        $this->assertNull(Zone::find($unused->id));
        $this->assertSame(0, $this->grid->rules()->where('origin_zone_id', $unused->id)->count());

        // Commune avec quartiers, zone de ramassage d'un marchand, zone de courses
        $this->deleteJson("/api/v1/zones/{$this->cocody->id}")->assertUnprocessable()
            ->assertJsonPath('message', fn ($m) => str_contains($m, '1 quartier(s)') && str_contains($m, '1 e-commerçant(s)'));
        $this->createOrder();
        $this->deleteJson("/api/v1/zones/{$this->yopougon->id}")->assertUnprocessable()
            ->assertJsonPath('message', fn ($m) => str_contains($m, '1 course(s)') && str_contains($m, 'Désactivez-la'));
        $this->assertNotNull($this->yopougon->fresh());

        Sanctum::actingAs($this->dispatcher);
        $this->deleteJson("/api/v1/zones/{$this->angre->id}")->assertForbidden();
    }

    public function test_admin_replaces_the_price_matrix(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/pricing-grids/{$this->grid->id}/rules", ['rules' => [
            ['origin_zone_id' => $this->cocody->id, 'destination_zone_id' => $this->plateau->id, 'price' => 2000],
            ['origin_zone_id' => $this->plateau->id, 'destination_zone_id' => $this->plateau->id, 'price' => 1000],
        ]])->assertOk()->assertJsonCount(2, 'data.rules');

        $this->postJson('/api/v1/quotes', ['merchant_id' => $this->merchant->id, 'delivery_zone_id' => $this->plateau->id])
            ->assertJsonPath('data.total', 2000);
    }

    public function test_duplicate_routes_are_rejected(): void
    {
        Sanctum::actingAs($this->admin);
        $route = ['origin_zone_id' => $this->cocody->id, 'destination_zone_id' => $this->plateau->id, 'price' => 2000];

        $this->putJson("/api/v1/pricing-grids/{$this->grid->id}/rules", ['rules' => [$route, $route]])
            ->assertJsonValidationErrors('rules');
    }

    public function test_pricing_grids_of_another_company_are_not_found(): void
    {
        $foreign = PricingGrid::factory()->create();
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/v1/pricing-grids/{$foreign->id}")->assertNotFound();
    }

    public function test_a_single_default_grid_and_it_cannot_be_deleted(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/v1/pricing-grids', ['name' => 'Nouvelle', 'is_default' => true])->json('data.id');

        $this->assertFalse($this->grid->fresh()->is_default);
        $this->deleteJson("/api/v1/pricing-grids/{$id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/pricing-grids/{$this->grid->id}")->assertNoContent();
    }

    public function test_admin_creates_a_merchant_with_its_login_account(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/v1/merchants', [
            'business_name' => 'Chez Fatou',
            'phone' => '05 11 22 33 44',
            'pickup_zone_id' => $this->plateau->id,
            'owner' => ['name' => 'Fatou', 'phone' => '0511223344', 'password' => 'motdepasse'],
        ])->assertCreated()
            ->assertJsonPath('data.phone', '+2250511223344')
            ->assertJsonPath('data.users.0.role', 'merchant_owner')
            ->json('data.id');

        $this->postJson('/api/v1/auth/login', ['login' => '0511223344', 'password' => 'motdepasse'])
            ->assertOk()
            ->assertJsonPath('user.role', 'merchant_owner');

        $owner = User::where('phone', '+2250511223344')->first();
        $this->assertSame($id, $owner->merchant_id);
        $this->assertSame($this->company->id, $owner->company_id);
    }

    public function test_suspending_a_merchant_blocks_its_users(): void
    {
        $token = $this->merchantUser->createToken('test')->plainTextToken;
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/v1/merchants/{$this->merchant->id}", ['status' => 'suspended'])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_merchant_sees_its_profile_but_not_internal_notes_nor_other_merchants(): void
    {
        $this->merchant->update(['notes' => 'Paie toujours en retard']);
        Sanctum::actingAs($this->merchantUser);

        $this->getJson("/api/v1/merchants/{$this->merchant->id}")->assertOk()->assertJsonMissingPath('data.notes');
        $this->getJson('/api/v1/merchants')->assertForbidden();
    }

    public function test_creating_a_courier_account_creates_its_profile_and_zones_can_be_set(): void
    {
        Sanctum::actingAs($this->admin);

        $userId = $this->postJson('/api/v1/users', [
            'name' => 'Yao', 'phone' => '0102030405', 'password' => 'motdepasse', 'role' => 'courier',
        ])->assertCreated()->json('data.id');

        $courier = User::find($userId)->courier;
        $this->assertNotNull($courier);

        $this->patchJson("/api/v1/couriers/{$courier->id}", [
            'vehicle_type' => 'velo',
            'zone_ids' => [$this->cocody->id, $this->angre->id],
        ])->assertOk()->assertJsonCount(2, 'data.zones');

        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/couriers?zone_id='.$this->cocody->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Yao');
    }

    public function test_couriers_list_is_reserved_to_dispatch(): void
    {
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/couriers')->assertForbidden();
    }

    public function test_new_company_is_provisioned_with_abidjan_communes(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $id = $this->postJson('/api/v1/companies', ['name' => 'Nouvelle Express'])->assertCreated()->json('data.id');

        $this->assertSame(13, Zone::forCompany($id)->count());
        $this->assertTrue(PricingGrid::forCompany($id)->where('is_default', true)->exists());
    }

    public function test_incident_reasons_are_listed(): void
    {
        Sanctum::actingAs($this->courierA->user);

        $codes = collect($this->getJson('/api/v1/incident-reasons')->assertOk()->json('data'))->pluck('code');

        $this->assertContains('unreachable', $codes);
        $this->assertContains('postponed', $codes);
    }
}
