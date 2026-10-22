<?php

namespace Tests\Feature\Api;

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Notifications\OrderAlert;
use App\Services\Couriers\OverdueParcels;
use App\Services\Couriers\PickupCustody;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Après une mission ramassage : le livreur garde les colis jusqu'à leur livraison, ou les
 * rapporte au dépôt (réglage de l'entreprise, ou choix du dispatch à chaque mission).
 */
class PickupCustodyTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->company->update(['timezone' => 'Africa/Abidjan', 'parcel_hold_alert_hours' => 24]);
        $this->travelTo(now('Africa/Abidjan')->setTime(17, 0));
        Sanctum::actingAs($this->dispatcher);
    }

    private function forTomorrow(): Order
    {
        $order = $this->createOrder(['delivery_scheduled_date' => today()->addDay()->toDateString()]);
        $order->forceFill(['status' => OrderStatus::Confirmed, 'confirmed_at' => now()])->save();

        return $order->fresh();
    }

    private function mission(Order $order, ?string $custody = null)
    {
        return $this->postJson("/api/v1/couriers/{$this->courierA->id}/pickup-mission", array_filter(['order_ids' => [$order->id], 'custody' => $custody]));
    }

    /** Ramassage fait : le colis est chez le livreur depuis $hoursAgo heures */
    private function pickedUp(Order $order, int $hoursAgo = 0): Order
    {
        $order->refresh();
        $order->assignments()->active()->update(['status' => 'completed', 'completed_at' => now()]);
        $order->forceFill(['status' => OrderStatus::PickedUp, 'held_by_courier_id' => $this->courierA->id, 'held_since' => now()->subHours($hoursAgo)])->save();

        return $order->fresh();
    }

    public function test_the_company_chooses_keep_depot_or_lets_dispatch_decide(): void
    {
        $order = $this->forTomorrow();

        // Par défaut, le dispatch choisit à chaque mission
        $this->mission($order)->assertUnprocessable()->assertJsonValidationErrors('custody');
        $this->mission($order, 'keep')->assertOk()->assertJsonPath('data.custody', 'keep')->assertJsonPath('data.fallback', false);
        $this->assertSame('keep', $order->fresh()->custody_plan);
        $this->assertStringContainsString('Il garde le colis', $order->events()->latest('id')->first()->note);

        // Réglage fixe : le choix du dispatch est ignoré
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['pickup_mission_custody' => 'depot', 'depot_closes_at' => '19:00'])
            ->assertOk()->assertJsonPath('data.pickup_mission_custody', 'depot')->assertJsonPath('data.depot_closes_at', '19:00');
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['pickup_mission_custody' => 'toujours'])->assertJsonValidationErrors('pickup_mission_custody');

        Sanctum::actingAs($this->dispatcher);
        $other = $this->forTomorrow();
        $this->mission($other, 'keep')->assertOk()->assertJsonPath('data.custody', 'depot');
        $this->assertSame('depot', $other->fresh()->custody_plan);
        $this->dispatcher->unsetRelation('company'); // entreprise relue, comme à chaque requête
        $this->getJson('/api/v1/couriers/availability')->assertJsonPath('meta.custody', 'depot')->assertJsonPath('meta.depot_closed', false);

        // Dépôt fermé à cette heure : le livreur garde les colis jusqu'au matin
        $this->travelTo(now()->setTime(19, 30));
        $late = $this->forTomorrow();
        $this->mission($late)->assertOk()->assertJsonPath('data.custody', 'keep')->assertJsonPath('data.fallback', true);
        $this->assertStringContainsString('dépôt fermé', $late->events()->latest('id')->first()->note);
    }

    public function test_kept_parcels_are_not_overdue_before_their_delivery_day_but_depot_ones_are(): void
    {
        Notification::fake();
        $kept = $this->forTomorrow();
        $depot = $this->forTomorrow();
        $this->mission($kept, 'keep');
        $this->mission($depot, 'depot');
        $kept = $this->pickedUp($kept, 30);
        $depot = $this->pickedUp($depot, 30);

        app(OverdueParcels::class)->run();
        Notification::assertSentTo($this->dispatcher, OrderAlert::class, fn ($n) => $n->kind === 'parcel_overdue' && str_contains($n->body, $depot->tracking_code) && ! str_contains($n->body, $kept->tracking_code));

        // « Colis chez les livreurs » : gardé pour sa livraison, pas à rendre
        $parcels = collect($this->getJson('/api/v1/parcels/held')->json('data.0.parcels'))->keyBy('id');
        $this->assertSame(today()->addDay()->toDateString(), $parcels[$kept->id]['kept_for']);
        $this->assertNull($parcels[$depot->id]['kept_for']);
        $this->assertSame('depot', $parcels[$depot->id]['custody_plan']);

        // Rendu au dépôt : la garde prévue s'arrête
        $this->postJson("/api/v1/couriers/{$this->courierA->id}/parcels/receive", ['order_ids' => [$kept->id]])->assertOk();
        $this->assertNull($kept->fresh()->custody_plan);
        $this->assertSame(OrderStatus::AtHub, $kept->fresh()->status);
    }

    public function test_on_the_delivery_day_the_kept_parcel_goes_to_its_holder_and_a_handover_is_flagged(): void
    {
        $kept = $this->forTomorrow();
        $other = $this->forTomorrow();
        $this->mission($kept, 'keep');
        $this->mission($other, 'keep');
        $kept = $this->pickedUp($kept);
        $other = $this->pickedUp($other);

        // Pas avant le jour prévu
        $this->assertSame(0, app(PickupCustody::class)->assignKeptDeliveries());

        // Le dispatch confie l'autre colis à un autre livreur : passation signalée, plus de garde prévue
        $this->postJson('/api/v1/orders/bulk-assign', ['order_ids' => [$other->id], 'type' => 'delivery', 'courier_id' => $this->courierB->id])
            ->assertOk()
            ->assertJsonPath("data.warnings.{$other->id}", "{$other->tracking_code} : colis chez Koffi Ramasseur, prévoir la passation.");
        $this->assertNull($other->fresh()->custody_plan);
        $this->assertStringContainsString('Passation', $other->events()->latest('id')->first()->note);

        $this->travelTo(now()->addDay()->setTime(6, 0));
        $this->assertSame(1, app(PickupCustody::class)->assignKeptDeliveries());
        $kept->refresh();
        $this->assertSame(OrderStatus::DeliveryAssigned, $kept->status);
        $this->assertSame($this->courierA->id, $kept->activeAssignment(AssignmentType::Delivery)->courier_id);
        $this->assertStringContainsString('confiée automatiquement', $kept->events()->latest('id')->first()->note);
        $this->assertSame(0, app(PickupCustody::class)->assignKeptDeliveries());
    }
}
