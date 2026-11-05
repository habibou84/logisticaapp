<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Courier;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Page Livreurs (reprise de Jibiat, adaptée) : tableau avec chiffres, filtres et tri ; fiche complète du livreur.
 */
class CourierDirectoryTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    private function deliveredBy(Courier $courier): Order
    {
        $order = $this->createOrder(['items_amount' => 10000]);
        $this->as($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $courier->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $courier->id]);
        $this->as($courier->user)->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered', 'delivery_code' => $order->fresh()->delivery_code])->assertOk();

        return $order->fresh();
    }

    public function test_tableau_avec_chiffres_tri_et_pagination(): void
    {
        $this->deliveredBy($this->courierB);
        $this->deliveredBy($this->courierB);
        $this->courierA->zones()->attach($this->yopougon->id);

        $res = $this->as($this->dispatcher)->getJson('/api/v1/couriers/directory?sort=delivered')->assertOk();
        $res->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.name', 'Awa Livreuse')
            ->assertJsonPath('data.0.delivered', 2)
            ->assertJsonPath('data.0.success_rate', 100)
            ->assertJsonPath('summary.couriers', 2)
            ->assertJsonPath('summary.delivered', 2);

        // Filtres : zone, recherche, page
        $this->getJson("/api/v1/couriers/directory?zone_id={$this->yopougon->id}")->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Koffi Ramasseur');
        $this->getJson('/api/v1/couriers/directory?search=awa')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->courierB->id);
        $this->getJson('/api/v1/couriers/directory?rating=below')->assertOk();
        $page = $this->getJson('/api/v1/couriers/directory?per_page=10&page=5')->assertOk();
        $page->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.last_page', 1);

        // Compte désactivé : seulement avec « inactifs »
        $this->courierA->user->update(['status' => 'suspended']);
        $this->getJson('/api/v1/couriers/directory')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/couriers/directory?status=inactive')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->courierA->id);
    }

    public function test_fiche_du_livreur(): void
    {
        $this->deliveredBy($this->courierB);

        $res = $this->as($this->admin)->getJson("/api/v1/couriers/{$this->courierB->id}/profile?days=7")->assertOk();
        $res->assertJsonPath('data.courier.name', 'Awa Livreuse')
            ->assertJsonPath('data.figures.delivered', 1)
            ->assertJsonPath('data.days', 7)
            ->assertJsonStructure(['data' => ['score', 'today', 'mad', 'field_event', 'vehicle', 'vehicle_warnings', 'with_amounts']]);
        $this->assertNotNull($res->json('data.figures.earnings'));
        $this->assertCount(7, $res->json('data.series'));
        $this->assertSame(1, collect($res->json('data.series'))->sum('delivered'));
    }

    public function test_reserve_au_dispatch_et_a_l_entreprise(): void
    {
        $this->as($this->merchantUser)->getJson('/api/v1/couriers/directory')->assertForbidden();

        $other = Company::factory()->create();
        $stranger = User::factory()->create(['company_id' => $other->id]);
        $stranger->assignRole('admin');
        $this->as($stranger)->getJson('/api/v1/couriers/directory')->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/couriers/{$this->courierB->id}/profile")->assertNotFound();
    }
}
