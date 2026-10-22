<?php

namespace Tests\Feature\Api;

use App\Enums\AssignmentType;
use App\Models\Merchant;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Ramassages par client : lots à assigner d'un coup, colis tardifs ajoutés au livreur déjà prévu.
 */
class PickupGroupsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function confirmed(?Merchant $merchant = null, array $data = []): Order
    {
        $order = $this->createOrder($data, null, $merchant);
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();

        return $order->fresh();
    }

    public function test_pickups_to_assign_are_grouped_by_client_and_assigned_in_one_go(): void
    {
        $a1 = $this->confirmed();
        $a2 = $this->confirmed();
        $a3 = $this->confirmed();
        $other = Merchant::factory()->create(['company_id' => $this->company->id, 'business_name' => 'Autre boutique', 'pickup_zone_id' => $this->yopougon->id, 'pickup_address' => 'Siporex']);
        $b1 = $this->confirmed($other, ['delivery_zone_id' => $this->cocody->id, 'delivery_address' => 'Cocody Angré']);

        Sanctum::actingAs($this->dispatcher);
        $groups = $this->getJson('/api/v1/orders/pickup-groups')->assertOk()->json('data');
        $this->assertCount(2, $groups);
        $boutique = collect($groups)->firstWhere('merchant.id', $this->merchant->id);
        $this->assertSame(3, $boutique['count']);
        $this->assertSame([$a1->id, $a2->id, $a3->id], array_column($boutique['orders'], 'id'));
        $this->assertSame('Riviera 2', $boutique['pickup']['address']);

        // Un colis retiré du lot (pas prêt) : les deux autres partent avec le livreur
        $this->postJson('/api/v1/orders/bulk-assign', ['order_ids' => [$a1->id, $a2->id], 'type' => 'pickup', 'courier_id' => $this->courierA->id])
            ->assertOk()->assertJsonCount(2, 'data.assigned');

        $groups = $this->getJson('/api/v1/orders/pickup-groups')->json('data');
        $this->assertSame(1, collect($groups)->firstWhere('merchant.id', $this->merchant->id)['count']);
        $this->assertSame([$b1->id], array_column(collect($groups)->firstWhere('merchant.id', $other->id)['orders'], 'id'));

        // Réservé au dispatch
        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/orders/pickup-groups')->assertForbidden();
    }

    public function test_a_late_parcel_joins_the_courier_already_planned_at_that_client(): void
    {
        $first = $this->confirmed();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$first->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
        // Le livreur accepte et part
        Sanctum::actingAs($this->courierA->user);
        $assignment = $first->activeAssignment(AssignmentType::Pickup);
        $this->postJson("/api/v1/courier/assignments/{$assignment->id}/accept")->assertOk();
        $this->postJson("/api/v1/orders/{$first->id}/status", ['status' => 'pickup_in_progress'])->assertOk();

        // Nouveau colis du même client, validé ensuite : ajouté au même livreur, déjà accepté
        $late = $this->confirmed();
        $this->assertSame('pickup_assigned', $late->status->value);
        $this->assertSame($this->courierA->id, $late->pickup_courier_id);
        $this->assertSame('accepted', $late->activeAssignment(AssignmentType::Pickup)->status->value);
        $this->assertTrue($late->events()->where('note', 'like', 'Ajouté automatiquement au ramassage%')->exists());

        // Côté livreur : une seule mission pour la boutique (même clé de regroupement)
        Sanctum::actingAs($this->courierA->user);
        $groups = collect($this->getJson('/api/v1/courier/missions')->assertOk()->json('data'))
            ->where('type', 'pickup')->pluck('pickup_group')->unique();
        $this->assertCount(1, $groups);

        // Validation automatique à la création : même chose
        $this->company->update(['auto_confirm_orders' => true]);
        $this->merchant->unsetRelation('company');
        $auto = $this->createOrder();
        $this->assertSame('pickup_assigned', $auto->fresh()->status->value);
        $this->assertSame($this->courierA->id, $auto->fresh()->pickup_courier_id);
    }

    public function test_no_late_join_after_pickup_or_at_another_address(): void
    {
        $first = $this->confirmed();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$first->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();

        // Autre adresse de ramassage du même client : pas regroupé
        $elsewhere = $this->confirmed(null, ['pickup_address' => 'Boutique 2, Riviera 3']);
        $this->assertSame('confirmed', $elsewhere->status->value);

        // Ramassage déjà fait : le colis suivant attend une nouvelle assignation
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$first->id}/status", ['status' => 'picked_up'])->assertOk();
        $after = $this->confirmed();
        $this->assertSame('confirmed', $after->status->value);
        $this->assertNull($after->pickup_courier_id);
    }
}
