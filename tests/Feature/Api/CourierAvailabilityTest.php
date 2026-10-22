<?php

namespace Tests\Feature\Api;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Notifications\CourierAvailableAlert;
use App\Services\Couriers\CourierAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Livreurs libres ou bientôt libres : zone d'arrivée, ramassages près d'eux, alerte du dispatch
 * et mission ramassage (la date de livraison ne change pas).
 */
class CourierAvailabilityTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->courierA->update(['is_available' => true]);
        $this->courierB->update(['is_available' => true]);
    }

    private function confirmed(array $data = [], ?Merchant $merchant = null): Order
    {
        $order = $this->createOrder($data, null, $merchant);
        $order->forceFill(['status' => OrderStatus::Confirmed, 'confirmed_at' => now()])->save();

        return $order->fresh();
    }

    /** Arrêt terminé aujourd'hui par le livreur (livraison chez un client de la zone) */
    private function finishedStop(Courier $courier, int $deliveryZoneId, AssignmentType $type = AssignmentType::Delivery): void
    {
        $order = $this->confirmed(['delivery_zone_id' => $deliveryZoneId]);
        $order->assignments()->create([
            'courier_id' => $courier->id, 'type' => $type, 'status' => AssignmentStatus::Completed,
            'assigned_at' => now()->subHour(), 'completed_at' => now()->subMinutes(5),
        ]);
        $order->forceFill(['status' => OrderStatus::Delivered])->save();
    }

    /** Livraisons en cours (chez des clients de Cocody) */
    private function busy(Courier $courier, int $missions): void
    {
        for ($i = 0; $i < $missions; $i++) {
            $order = $this->confirmed(['delivery_zone_id' => $this->cocody->id]);
            $order->assignments()->create([
                'courier_id' => $courier->id, 'type' => AssignmentType::Delivery, 'status' => AssignmentStatus::Accepted, 'assigned_at' => now(),
            ]);
            $order->forceFill(['status' => OrderStatus::OutForDelivery])->save();
        }
    }

    public function test_board_shows_load_arrival_zone_and_pickups_nearby(): void
    {
        $this->finishedStop($this->courierA, $this->cocody->id);
        // Deux colis à ramasser chez la boutique de Cocody (l'un pour demain), un chez une boutique de Yopougon
        $this->confirmed();
        $this->confirmed(['delivery_scheduled_date' => today()->addDay()->toDateString()]);
        $far = Merchant::factory()->create(['company_id' => $this->company->id, 'business_name' => 'Loin', 'pickup_zone_id' => $this->yopougon->id, 'pickup_address' => 'Siporex']);
        $this->confirmed(['delivery_zone_id' => $this->cocody->id], $far);
        $this->busy($this->courierB, 3);

        Sanctum::actingAs($this->dispatcher);
        $board = collect($this->getJson('/api/v1/couriers/availability')->assertOk()->json('data'))->keyBy('id');

        $a = $board[$this->courierA->id];
        $this->assertSame('free', $a['state']);
        $this->assertSame(0, $a['remaining']['total']);
        $this->assertSame(1, $a['done_today']);
        $this->assertSame('Cocody', $a['arrival_zone']['name']);
        $this->assertSame(['count' => 2, 'stops' => 1, 'for_tomorrow' => 1], $a['nearby']);

        $this->assertSame('busy', $board[$this->courierB->id]['state']);
        $this->assertSame(3, $board[$this->courierB->id]['remaining']['delivery']);

        // Détail : l'arrêt de la boutique, dans sa zone d'arrivée
        $stops = $this->getJson("/api/v1/couriers/{$this->courierA->id}/availability")->json('data.stops');
        $this->assertCount(1, $stops);
        $this->assertSame('arrival_zone', $stops[0]['reason']);
        $this->assertSame('Boutique Test', $stops[0]['merchant']);

        // Ses zones habituelles comptent aussi ; une position récente ramène ce qui est tout près
        $this->courierA->zones()->attach($this->yopougon->id);
        $this->assertSame(3, $this->getJson('/api/v1/couriers/availability')->json('data.0.nearby.count'));

        $this->courierA->zones()->detach();
        $far->update(['pickup_lat' => 5.3400, 'pickup_lng' => -4.0700]);
        $this->courierA->update(['current_lat' => 5.3450, 'current_lng' => -4.0720, 'last_location_at' => now()]);
        $stops = collect($this->getJson("/api/v1/couriers/{$this->courierA->id}/availability")->json('data.stops'));
        $this->assertSame('near', $stops->firstWhere('merchant', 'Loin')['reason']);
        $this->assertSame('Loin', $stops->first()['merchant']);

        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/couriers/availability')->assertForbidden();
    }

    public function test_dispatch_is_alerted_once_per_level_when_a_courier_frees_up_near_pickups(): void
    {
        Notification::fake();
        $this->finishedStop($this->courierA, $this->cocody->id);
        $this->busy($this->courierA, 2);
        $this->confirmed();

        // Alerte coupée par l'entreprise : rien
        $this->company->update(['courier_available_alert' => false]);
        $this->assertSame(0, $this->artisanAlert());
        $this->company->update(['courier_available_alert' => true]);

        // Bientôt libre (2 missions) : une alerte, pas de doublon
        $this->assertSame(1, $this->artisanAlert());
        Notification::assertSentTo([$this->admin, $this->dispatcher], CourierAvailableAlert::class,
            fn ($n, $channels, $user) => str_contains($n->toArray($user)['title'], 'bientôt libre à Cocody'));
        $this->assertSame(0, $this->artisanAlert());

        // Libre : nouvelle alerte (palier plus fort), puis plus rien
        $this->courierA->assignments()->active()->update(['status' => AssignmentStatus::Completed->value, 'completed_at' => now()]);
        $this->assertSame(1, $this->artisanAlert());
        $this->assertSame(0, $this->artisanAlert());

        // Livreur qui n'a pas encore travaillé, ou hors service : pas d'alerte
        $this->courierB->zones()->attach($this->cocody->id);
        $this->assertSame(0, $this->artisanAlert());
        $this->finishedStop($this->courierB, $this->cocody->id);
        $this->courierB->update(['is_available' => false]);
        $this->assertSame(0, $this->artisanAlert());
    }

    private function artisanAlert(): int
    {
        return app(CourierAvailability::class)->alert();
    }

    public function test_pickup_mission_assigns_the_chosen_parcels_without_changing_the_delivery_date(): void
    {
        $tomorrow = today()->addDay()->toDateString();
        $one = $this->confirmed(['delivery_scheduled_date' => $tomorrow]);
        $two = $this->confirmed();

        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/couriers/{$this->courierA->id}/pickup-mission", ['order_ids' => [$one->id, $two->id], 'custody' => 'depot'])
            ->assertOk()
            ->assertJsonPath('data.assigned', [$one->id, $two->id]);

        $one->refresh();
        $this->assertSame(OrderStatus::PickupAssigned, $one->status);
        $this->assertSame($this->courierA->id, $one->activeAssignment(AssignmentType::Pickup)->courier_id);
        $this->assertSame($tomorrow, $one->delivery_scheduled_date->toDateString());
        $this->assertStringContainsString('Mission ramassage', $one->events()->latest('id')->first()->note);

        // Déjà confié : laissé de côté, pas réassigné à un autre livreur
        $this->postJson("/api/v1/couriers/{$this->courierB->id}/pickup-mission", ['order_ids' => [$one->id], 'custody' => 'depot'])
            ->assertOk()
            ->assertJsonPath('data.assigned', [])
            ->assertJsonPath("data.errors.{$one->id}", 'Un livreur va déjà chercher ce colis.');
        $this->assertSame($this->courierA->id, $one->fresh()->activeAssignment(AssignmentType::Pickup)->courier_id);

        $this->postJson("/api/v1/couriers/{$this->courierA->id}/pickup-mission", ['order_ids' => []])->assertJsonValidationErrors('order_ids');
    }
}
