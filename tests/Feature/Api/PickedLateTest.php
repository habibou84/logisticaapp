<?php

namespace Tests\Feature\Api;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Courses en retard › « À décider » : liste toutes dates, retour (confié tout de suite ou plus tard)
 * ou report à une nouvelle date ; le colis sort alors de la liste.
 */
class PickedLateTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Sanctum::actingAs($this->dispatcher);
    }

    private function late(OrderStatus $status, ?int $heldBy = null, int $daysAgo = 3): Order
    {
        $order = $this->createOrder();
        $order->forceFill(['status' => $status, 'held_by_courier_id' => $heldBy, 'held_since' => $heldBy ? now()->subDays($daysAgo) : null,
            'pickup_courier_id' => $this->courierA->id, 'delivery_scheduled_date' => today()->subDays($daysAgo)->toDateString()])->save();

        return $order->fresh();
    }

    private function ids(): array
    {
        return collect($this->getJson('/api/v1/orders?queue=picked_late&day='.today()->toDateString())->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_picked_parcels_past_their_date_are_returned_or_rescheduled(): void
    {
        $withKoffi = $this->late(OrderStatus::PickedUp, $this->courierA->id);
        $failed = $this->late(OrderStatus::DeliveryFailed, $this->courierB->id);
        $assigned = $this->late(OrderStatus::DeliveryAssigned, $this->courierA->id);
        OrderAssignment::create(['order_id' => $assigned->id, 'courier_id' => $this->courierA->id, 'type' => AssignmentType::Delivery, 'status' => AssignmentStatus::Accepted, 'order_status_before' => OrderStatus::PickedUp, 'assigned_at' => now()->subDays(3)]);
        $notPicked = $this->late(OrderStatus::Confirmed);
        $today = $this->createOrder();
        $today->forceFill(['status' => OrderStatus::PickedUp, 'delivery_scheduled_date' => today()->toDateString()])->save();

        // Toutes dates, même avec le jour choisi ; ni les jamais ramassés ni ceux du jour
        $this->assertEqualsCanonicalizing([$withKoffi->id, $failed->id, $assigned->id], $this->ids());
        $this->assertSame(3, $this->getJson('/api/v1/orders/counts')->json('data.exceptions.picked_late'));

        // Retour confié à celui qui a le colis
        $this->postJson('/api/v1/orders/picked-late/decide', ['decision' => 'return', 'order_ids' => [$withKoffi->id], 'holder' => true])
            ->assertOk()->assertJsonPath('data.done', 1);
        $this->assertSame([OrderStatus::ReturnAssigned, $this->courierA->id, true], [$withKoffi->fresh()->status, $withKoffi->fresh()->return_courier_id, $withKoffi->fresh()->return_requested]);

        // Retour plus tard : la livraison en cours est retirée, le colis attend dans Retours clients
        $this->postJson('/api/v1/orders/picked-late/decide', ['decision' => 'return', 'order_ids' => [$assigned->id]])->assertOk();
        $this->assertTrue($assigned->fresh()->return_requested);
        $this->assertNull($assigned->fresh()->activeAssignment(AssignmentType::Delivery));
        $this->assertContains($assigned->id, collect($this->getJson('/api/v1/return-slips')->json('candidates.0.orders'))->pluck('id'));

        // Report : nouvelle date, le livreur qui a le colis le garde
        $tomorrow = today()->addDay()->toDateString();
        $this->postJson('/api/v1/orders/picked-late/decide', ['decision' => 'reschedule', 'order_ids' => [$failed->id], 'date' => $tomorrow])->assertOk();
        $this->assertSame($tomorrow, $failed->fresh()->delivery_scheduled_date->toDateString());
        $this->assertSame($this->courierB->id, $failed->fresh()->activeAssignment(AssignmentType::Delivery)?->courier_id);

        $this->assertSame([], $this->ids());

        // Déjà décidé, date passée, colis jamais ramassé : refusés avec la raison
        $this->postJson('/api/v1/orders/picked-late/decide', ['decision' => 'reschedule', 'order_ids' => [$notPicked->id], 'date' => $tomorrow])
            ->assertUnprocessable()->assertJsonPath('data.errors.0.id', $notPicked->id);
        $this->postJson('/api/v1/orders/picked-late/decide', ['decision' => 'reschedule', 'order_ids' => [$failed->id]])->assertJsonValidationErrors('date');

        // Réservé au dispatch
        Sanctum::actingAs($this->merchantUser);
        $this->postJson('/api/v1/orders/picked-late/decide', ['decision' => 'return', 'order_ids' => [$failed->id]])->assertForbidden();
    }
}
