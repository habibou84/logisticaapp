<?php

namespace Tests\Feature\Api;

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\OrderEvent;
use App\Services\Orders\OrderDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Tournée du livreur (à vol d'oiseau, organisée par lui) et collecte avec scan facultatif des étiquettes.
 */
class CourierRouteTest extends TestCase
{
    use BuildsDeliveryWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->rule($this->grid, $this->cocody, $this->plateau, 1500);
        $this->travelTo('2026-11-23 09:00:00');
    }

    /** Livraison confiée au livreur A, à cette position (ou sans) */
    private function delivery(string $name, ?array $point, array $data = []): Order
    {
        $order = $this->createOrder(['recipient_name' => $name, 'delivery_lat' => $point[0] ?? null, 'delivery_lng' => $point[1] ?? null, ...$data]);
        $order->forceFill(['status' => OrderStatus::PickedUp])->save();
        app(OrderDispatcher::class)->assign($this->dispatcher, $order->fresh(), AssignmentType::Delivery, $this->courierA);

        return $order->fresh();
    }

    private function names(array $route): array
    {
        return array_map(fn (array $s) => implode('+', array_column($s['orders'], 'recipient')), $route['stops']);
    }

    public function test_courier_organises_deliveries_from_his_position(): void
    {
        // Sur une ligne est-ouest, depuis l'ouest : A (1 km), B (2 km), C (3 km), D à la même adresse que B
        $this->delivery('C', [5.3, -3.973]);
        $this->delivery('A', [5.3, -3.991]);
        $this->delivery('B', [5.3, -3.982]);
        $this->delivery('D', [5.3, -3.9821]);
        $this->delivery('Inconnu', null, ['delivery_zone_id' => $this->plateau->id]);

        Sanctum::actingAs($this->courierA->user);
        $route = $this->postJson('/api/v1/courier/route/optimize', ['lat' => 5.3, 'lng' => -4.0])->assertOk()->json('data');
        $this->assertSame(['A', 'B+D', 'C', 'Inconnu'], $this->names($route));
        $this->assertSame(['exact', 'exact', 'exact', 'unknown'], array_column($route['stops'], 'precision'));
        $this->assertSame(0, $route['unplaced']);

        // L'ordre est gardé sur ses missions : la liste « Livrer » le suit
        $missions = collect($this->getJson('/api/v1/courier/missions')->assertOk()->json('data'))->where('type', 'delivery')->values();
        $this->assertSame(['A', 'B', 'D', 'C', 'Inconnu'], $missions->map(fn ($m) => $m['order']['recipient']['name'])->all());
        $this->assertSame([1, 2, 2, 3, 4], $missions->pluck('route_position')->all());

        // Ordre changé à la main : C en premier
        $ids = array_merge(...array_column($route['stops'], 'assignment_ids'));
        $c = $route['stops'][2]['assignment_ids'];
        $this->putJson('/api/v1/courier/route', ['assignment_ids' => [...$c, ...array_diff($ids, $c)]])->assertOk()
            ->assertJsonPath('data.stops.0.orders.0.recipient', 'C');
        $this->assertSame(['C', 'A', 'B+D', 'Inconnu'], $this->names($this->getJson('/api/v1/courier/route')->json('data')));

        // Nouvelle livraison : pas encore placée, à la fin
        $this->delivery('E', [5.3, -3.995]);
        $route = $this->getJson('/api/v1/courier/route')->assertOk()->json('data');
        $this->assertSame(1, $route['unplaced']);
        $this->assertSame('E', last($this->names($route)));
    }

    public function test_express_first_and_unknown_area_placed_by_its_history(): void
    {
        // Livraisons déjà faites au Plateau : le quartier est situé
        Order::withoutGlobalScopes()->where('id', $this->createOrder(['delivery_zone_id' => $this->plateau->id])->id)
            ->update(['delivery_lat' => 5.32, 'delivery_lng' => -4.02, 'status' => OrderStatus::Delivered->value]);

        $this->delivery('Proche', [5.3, -3.999]);
        $this->delivery('Express loin', [5.3, -3.95], ['is_express' => true]);
        $this->delivery('Plateau', null, ['delivery_zone_id' => $this->plateau->id]);

        Sanctum::actingAs($this->courierA->user);
        $route = $this->postJson('/api/v1/courier/route/optimize', ['lat' => 5.3, 'lng' => -4.0])->assertOk()->json('data');
        $this->assertSame('Express loin', $route['stops'][0]['orders'][0]['recipient']);
        $plateau = collect($route['stops'])->firstWhere('orders.0.recipient', 'Plateau');
        $this->assertSame('zone', $plateau['precision']);
        $this->assertEqualsWithDelta(5.32, $plateau['lat'], 0.0001);
    }

    public function test_courier_only_organises_his_own_deliveries(): void
    {
        $mine = $this->delivery('A', [5.3, -3.99]);
        $other = $this->createOrder(['recipient_name' => 'Autre']);
        $other->forceFill(['status' => OrderStatus::PickedUp])->save();
        $theirs = app(OrderDispatcher::class)->assign($this->dispatcher, $other->fresh(), AssignmentType::Delivery, $this->courierB);

        Sanctum::actingAs($this->courierA->user);
        $this->putJson('/api/v1/courier/route', ['assignment_ids' => [$theirs->id]])->assertOk();
        $this->assertNull($theirs->fresh()->route_position);
        $this->assertSame(1, OrderAssignment::where('order_id', $mine->id)->value('route_position'));

        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/courier/route')->assertForbidden();
    }

    public function test_itinerary_covers_pickups_and_returns_and_never_delivers_before_picking_up(): void
    {
        // D'ouest en est : livraison de X (1 km), ramassage de X (2 km), livraison de Y (3 km), retour chez le client (4 km)
        $x = $this->createOrder(['recipient_name' => 'X', 'delivery_lat' => 5.3, 'delivery_lng' => -3.991, 'pickup_lat' => 5.3, 'pickup_lng' => -3.982]);
        app(OrderDispatcher::class)->assign($this->dispatcher, $x->fresh(), AssignmentType::Pickup, $this->courierA);
        OrderAssignment::create(['order_id' => $x->id, 'courier_id' => $this->courierA->id, 'type' => AssignmentType::Delivery, 'status' => 'accepted', 'order_status_before' => OrderStatus::PickedUp, 'assigned_at' => now()]);
        $this->delivery('Y', [5.3, -3.973]);
        $back = $this->createOrder(['recipient_name' => 'Z', 'pickup_lat' => 5.3, 'pickup_lng' => -3.964]);
        $back->forceFill(['status' => OrderStatus::DeliveryFailed, 'return_requested' => true])->save();
        app(OrderDispatcher::class)->assign($this->dispatcher, $back->fresh(), AssignmentType::Return, $this->courierA);

        Sanctum::actingAs($this->courierA->user);
        $route = $this->postJson('/api/v1/courier/route/optimize', ['lat' => 5.3, 'lng' => -4.0])->assertOk()->json('data');
        $types = array_map(fn (array $s) => implode('+', array_column($s['orders'], 'type')), $route['stops']);
        // Le plus proche est la livraison de X, mais son colis n'est pas encore ramassé : elle passe juste après le ramassage
        $this->assertSame(['pickup', 'delivery', 'delivery', 'return'], $types);
        $this->assertSame([$this->merchant->business_name, 'X', 'Y', $this->merchant->business_name], $this->names($route));

        // Rangée à la main dans le mauvais sens : remise dans l'ordre
        $ids = array_merge(...array_column(array_reverse($route['stops']), 'assignment_ids'));
        $manual = $this->putJson('/api/v1/courier/route', ['assignment_ids' => $ids])->assertOk()->json('data');
        $this->assertSame(['return', 'delivery', 'pickup', 'delivery'], array_map(fn (array $s) => $s['orders'][0]['type'], $manual['stops']));
    }

    public function test_pickup_scan_is_noted_in_the_order_history(): void
    {
        $scanned = $this->createOrder();
        $typed = $this->createOrder();
        $plain = $this->createOrder();
        foreach ([$scanned, $typed, $plain] as $order) {
            $order->forceFill(['status' => OrderStatus::Confirmed])->save();
            app(OrderDispatcher::class)->assign($this->dispatcher, $order->fresh(), AssignmentType::Pickup, $this->courierA)
                ->update(['status' => 'accepted']);
        }

        Sanctum::actingAs($this->courierA->user);
        foreach ([[$scanned, 'scan'], [$typed, 'code'], [$plain, null]] as [$order, $check]) {
            $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'pickup_in_progress'])->assertOk();
            $this->postJson("/api/v1/orders/{$order->id}/status", array_filter(['status' => 'picked_up', 'pickup_check' => $check]))->assertOk();
        }
        $this->postJson("/api/v1/orders/{$plain->id}/status", ['status' => 'at_hub', 'pickup_check' => 'photo'])->assertStatus(422)->assertJsonValidationErrors('pickup_check');

        $check = fn (Order $o) => OrderEvent::where('order_id', $o->id)->where('to_status', 'picked_up')->first()->meta['pickup_check'] ?? null;
        $this->assertSame(['scan', 'code', null], [$check($scanned), $check($typed), $check($plain)]);
    }
}
