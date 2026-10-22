<?php

namespace Tests\Feature\Api;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\VehicleType;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierAbsence;
use App\Models\CourierRule;
use App\Models\Order;
use App\Models\Zone;
use App\Services\Assignment\AutoAssigner;
use App\Services\Orders\OrderWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Livraison du jour même confiée automatiquement : conditions sur la course (jour, heure limite, zone, téléphone,
 * montant, retour, tentatives) et sur le livreur (en service, zone principale ou de secours, capacité, véhicule,
 * refus), ordre du choix (celui qui a le colis, planning, zone principale, déjà dans le secteur, moins chargé),
 * explication sur la course, essai, heure limite par jour de la semaine.
 */
class AutoDeliveryTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private Courier $moussa;

    private Zone $siporex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        // Lundi 9 h
        $this->travelTo(CarbonImmutable::parse('2026-11-23 09:00', 'UTC'));
        $this->company->update(['timezone' => 'UTC', 'auto_delivery' => Company::AUTO_ON]);
        $this->siporex = $this->zone('Siporex', $this->yopougon);
        $this->moussa = $this->userWithRole(Role::Courier, ['name' => 'Moussa Secours'])->courier;
        foreach ([$this->courierA, $this->courierB, $this->moussa] as $courier) {
            $courier->forceFill(['is_available' => true, 'last_seen_at' => now(), 'vehicle_type' => VehicleType::Moto])->save();
        }
        // Koffi : Yopougon en zone principale ; Moussa : Yopougon en secours ; Awa : Cocody
        $this->courierA->zones()->sync([$this->yopougon->id => ['is_backup' => false]]);
        $this->moussa->zones()->sync([$this->yopougon->id => ['is_backup' => true]]);
        $this->courierB->zones()->sync([$this->cocody->id => ['is_backup' => false]]);
    }

    /** Colis récupéré, à livrer à Yopougon */
    private function ready(array $attributes = []): Order
    {
        $order = $this->createOrder();
        $order->forceFill(['status' => OrderStatus::PickedUp, ...$attributes])->save();

        return $order->fresh();
    }

    private function deliverer(Order $order): ?string
    {
        app(AutoAssigner::class)->delivery($order);

        return $order->fresh()->activeAssignment(AssignmentType::Delivery)?->courier->user->name;
    }

    public function test_a_ready_parcel_goes_to_the_main_zone_courier_with_the_reason(): void
    {
        // Déposé au dépôt : la livraison part aussitôt, acceptée d'office, expliquée sur la course
        $order = $this->ready();
        app(OrderWorkflow::class)->transition($this->dispatcher, $order, OrderStatus::AtHub);
        $order->refresh();
        $mission = $order->activeAssignment(AssignmentType::Delivery);
        $this->assertSame([$this->courierA->id, AssignmentStatus::Accepted, 'auto'], [$mission->courier_id, $mission->status, $mission->source]);
        $this->assertSame('Livraison confiée automatiquement à Koffi Ramasseur : zone principale Yopougon, aucun colis en cours.',
            $order->events()->where('type', 'note')->latest('id')->first()->note);

        // Essai : rien n'est envoyé, le choix est noté
        $this->company->update(['auto_delivery' => Company::AUTO_TRIAL]);
        $trial = $this->ready(['delivery_zone_id' => $this->siporex->id]);
        $this->assertNull($this->deliverer($trial));
        $this->assertStringContainsString('la livraison aurait été confiée à Koffi Ramasseur : zone principale Yopougon, déjà 1 livraison dans le secteur, 1 colis en cours', $trial->events()->where('type', 'note')->sole()->note);

        // Rattrapage toutes les cinq minutes, une fois l'automatisme actif
        $this->company->update(['auto_delivery' => Company::AUTO_ON]);
        $this->artisan('assignments:auto')->assertSuccessful();
        $this->assertSame($this->courierA->id, $trial->fresh()->delivery_courier_id);
    }

    public function test_the_course_must_be_for_today_before_the_cutoff_and_safe(): void
    {
        $shipping = Zone::factory()->create(['company_id' => $this->company->id, 'name' => 'Bouaké', 'is_shipping' => true]);
        $this->company->update(['auto_delivery_max_cod' => 50000]);

        $this->assertNull($this->deliverer($this->ready(['delivery_scheduled_date' => '2026-11-24'])), 'prévue demain');
        $this->assertNull($this->deliverer($this->ready(['delivery_zone_id' => $shipping->id])), 'expédition');
        $this->assertNull($this->deliverer($this->ready(['cod_amount' => 80000])), 'au-dessus du plafond');
        $this->assertNull($this->deliverer($this->ready(['return_requested' => true])), 'retour demandé');
        $this->assertNull($this->deliverer($this->ready(['attempts_count' => 3, 'max_attempts' => 3])), 'tentatives épuisées');
        $this->assertNull($this->deliverer($this->ready(['recipient_phone' => '12'])), 'téléphone');

        // Heure limite : commune 15 h, le lundi 8 h 30 (heure passée)
        $this->company->update(['daily_cutoff_time' => '15:00', 'cutoff_by_day' => ['1' => '08:30', '6' => '12:00', '7' => null]]);
        $this->assertSame(['1' => '08:30', '6' => '12:00'], $this->company->fresh()->cutoff_by_day);
        $this->assertSame(['08:30', '15:00'], [$this->company->fresh()->cutoffFor('2026-11-23'), $this->company->fresh()->cutoffFor('2026-11-24')]);
        $this->assertNull($this->deliverer($this->ready()), "après l'heure limite du lundi");

        $this->company->update(['cutoff_by_day' => null]);
        $this->assertSame('Koffi Ramasseur', $this->deliverer($this->ready(['cod_amount' => 20000])));
    }

    public function test_the_courier_must_be_in_service_with_room_and_a_suitable_vehicle(): void
    {
        // Koffi en pause : Moussa (zone de secours)
        $this->courierA->update(['is_available' => false]);
        $this->assertSame('Moussa Secours', $this->deliverer($this->ready()));
        $this->courierA->update(['is_available' => true]);

        // Koffi absent ce jour-là, puis service fini
        CourierAbsence::create(['company_id' => $this->company->id, 'courier_id' => $this->courierA->id, 'starts_on' => '2026-11-23', 'ends_on' => '2026-11-23']);
        $this->assertSame('Moussa Secours', $this->deliverer($this->ready()));
        CourierAbsence::query()->delete();
        $this->courierA->update(['shift_ends_at' => '08:00']);
        $this->assertSame('Moussa Secours', $this->deliverer($this->ready()));
        $this->courierA->update(['shift_ends_at' => null]);

        // Koffi plein (1 colis au plus) ; Moussa à pied pour un grand colis : personne
        $this->courierA->update(['max_parcels' => 1]);
        $this->assertSame('Koffi Ramasseur', $this->deliverer($this->ready()));
        $this->assertSame('Moussa Secours', $this->deliverer($this->ready()));
        $this->moussa->update(['vehicle_type' => VehicleType::Pieton]);
        $this->assertNull($this->deliverer($this->ready(['package_size' => 'L'])));

        // Argent en main : 10 000 au plus, la course en encaisse 15 000
        $this->courierA->update(['max_parcels' => null, 'max_cash' => 10000]);
        $this->assertNull($this->deliverer($this->ready(['cod_amount' => 15000, 'package_size' => 'L'])));
    }

    public function test_a_refusal_sends_the_delivery_to_the_next_courier(): void
    {
        $order = $this->ready();
        $mission = app(AutoAssigner::class)->delivery($order);
        $this->assertSame($this->courierA->id, $mission->courier_id);

        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/courier/assignments/{$mission->id}/refuse", ['reason' => 'Moto en panne'])->assertOk();
        $order->refresh();
        $this->assertSame($this->moussa->id, $order->delivery_courier_id);
        $this->assertStringContainsString('zone de secours Yopougon, aucun colis en cours (empêchement de Koffi Ramasseur)', $order->events()->where('type', 'note')->latest('id')->first()->note);
    }

    public function test_the_choice_order_holder_planning_area_then_load(): void
    {
        // Moussa passe en zone principale lui aussi : Koffi et Moussa à égalité de zone
        $this->moussa->zones()->sync([$this->yopougon->id => ['is_backup' => false]]);

        // Le moins chargé : Koffi a déjà un colis à Cocody, Moussa aucun
        $this->courierB->zones()->sync([]);
        $cocody = $this->ready(['delivery_zone_id' => $this->cocody->id]);
        $this->courierA->zones()->attach($this->cocody->id, ['is_backup' => false]);
        $this->assertSame('Koffi Ramasseur', $this->deliverer($cocody));
        $this->assertSame('Moussa Secours', $this->deliverer($this->ready()));

        // Déjà dans le secteur : Moussa livre à Yopougon, il prend aussi Siporex (quartier de Yopougon)
        $this->assertSame('Moussa Secours', $this->deliverer($this->ready(['delivery_zone_id' => $this->siporex->id])));

        // Planning des livraisons : avant les zones
        CourierRule::create(['company_id' => $this->company->id, 'type' => 'delivery', 'courier_id' => $this->courierA->id, 'zone_ids' => [$this->yopougon->id]]);
        $planned = $this->ready();
        $this->assertSame('Koffi Ramasseur', $this->deliverer($planned));
        $this->assertStringContainsString('planning « Yopougon · tous les jours »', $planned->events()->where('type', 'note')->latest('id')->first()->note);

        // Celui qui a le colis passe avant tout, et le ramasseur si l'entreprise le veut
        $held = $this->ready(['held_by_courier_id' => $this->moussa->id]);
        $this->assertSame('Moussa Secours', $this->deliverer($held));
        $this->assertStringContainsString('il a déjà le colis', $held->events()->where('type', 'note')->latest('id')->first()->note);
        $this->company->update(['same_courier_delivery' => true]);
        $this->assertSame('Moussa Secours', $this->deliverer($this->ready(['pickup_courier_id' => $this->moussa->id])));
    }

    public function test_settings_and_courier_profile(): void
    {
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/companies/{$this->company->id}", [
            'auto_delivery' => 'trial', 'auto_delivery_max_cod' => 100000, 'same_courier_delivery' => true,
            'cutoff_by_day' => ['6' => '12:00', '7' => ''],
        ])->assertOk()->assertJsonPath('data.auto_delivery', 'trial')->assertJsonPath('data.cutoff_by_day.6', '12:00');

        $this->patchJson("/api/v1/couriers/{$this->moussa->id}", [
            'zone_ids' => [$this->cocody->id], 'backup_zone_ids' => [$this->yopougon->id, $this->cocody->id],
            'max_parcels' => 8, 'max_cash' => 150000, 'shift_ends_at' => '18:30',
        ])->assertOk()
            ->assertJsonPath('data.zones.0.id', $this->cocody->id)
            ->assertJsonPath('data.backup_zones.0.id', $this->yopougon->id)
            ->assertJsonCount(1, 'data.backup_zones')
            ->assertJsonPath('data.shift_ends_at', '18:30');
    }
}
