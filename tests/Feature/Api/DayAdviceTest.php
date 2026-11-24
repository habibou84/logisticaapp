<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Conseil du Poste de pilotage : rouvrir la journée quand des livreurs libres peuvent encore livrer, la fermer quand
 * les courses du jour sans livreur dépassent ce qu'ils peuvent prendre.
 */
class DayAdviceTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->company->update(['validation_mode' => 'rules', 'daily_cutoff_time' => '16:00', 'depot_closes_at' => '20:00', 'timezone' => 'Africa/Abidjan']);
        Sanctum::actingAs($this->dispatcher);
    }

    private function free(...$couriers): void
    {
        foreach ($couriers as $courier) {
            $courier->forceFill(['is_available' => true, 'last_seen_at' => now()])->save();
        }
    }

    private function waiting(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->createOrder()->forceFill(['status' => OrderStatus::Confirmed, 'delivery_scheduled_date' => today()->toDateString()])->save();
        }
    }

    public function test_after_the_cutoff_free_couriers_lead_to_reopen_until_a_given_hour(): void
    {
        $this->travelTo(now('Africa/Abidjan')->setTime(16, 30));
        $this->free($this->courierA, $this->courierB);
        $this->waiting(2);

        // 2 livreurs libres, 3 h 30 avant le dépôt à 20 h : environ 10 courses chacun
        $this->getJson('/api/v1/delivery-day/advice')->assertOk()
            ->assertJsonPath('data.kind', 'reopen')->assertJsonPath('data.free_couriers', 2)
            ->assertJsonPath('data.waiting', 2)->assertJsonPath('data.until', '18:30');

        $this->postJson('/api/v1/delivery-day/reopen', ['until' => '16:00'])->assertUnprocessable();
        $this->postJson('/api/v1/delivery-day/reopen', ['until' => '18:30'])->assertOk()
            ->assertJsonPath('data.closed', false)->assertJsonPath('data.cutoff_time', '18:30');
        $this->getJson('/api/v1/delivery-day/advice')->assertJsonPath('data', null);

        // Le lendemain, l'heure limite habituelle revient
        $this->travelTo(now()->addDay()->setTime(17, 0));
        $this->getJson('/api/v1/delivery-day')->assertJsonPath('data.closed', true)->assertJsonPath('data.cutoff_time', '16:00');
    }

    public function test_no_reopen_advice_without_enough_fresh_and_available_couriers(): void
    {
        $this->travelTo(now('Africa/Abidjan')->setTime(16, 30));
        $this->free($this->courierA);
        // Pas vu depuis une heure : ne compte pas
        $this->courierB->forceFill(['is_available' => true, 'last_seen_at' => now()->subHour()])->save();

        $this->getJson('/api/v1/delivery-day/advice')->assertJsonPath('data', null);

        // Critère réglable : un seul livreur suffit
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['day_advice' => ['min_couriers' => 1]])->assertOk()
            ->assertJsonPath('data.day_advice.min_couriers', 1)->assertJsonPath('data.day_advice.seen_minutes', 30);
        Sanctum::actingAs($this->dispatcher->fresh());
        $this->getJson('/api/v1/delivery-day/advice')->assertJsonPath('data.kind', 'reopen');

        // Trop près de la fermeture du dépôt
        $this->travelTo(now()->setTime(19, 0));
        $this->getJson('/api/v1/delivery-day/advice')->assertJsonPath('data', null);
    }

    public function test_before_the_cutoff_too_many_waiting_courses_lead_to_close_and_can_be_dismissed(): void
    {
        // Le matin : pas de conseil, même sans livreur
        $this->travelTo(now('Africa/Abidjan')->setTime(9, 0));
        $this->waiting(12);
        $this->getJson('/api/v1/delivery-day/advice')->assertJsonPath('data', null);

        // 14 h, 1 livreur libre jusqu'à 20 h avec 3 colis max : 12 courses en attente, c'est trop
        $this->travelTo(now()->setTime(14, 0));
        $this->free($this->courierA);
        $this->courierA->forceFill(['max_parcels' => 3])->save();
        $this->getJson('/api/v1/delivery-day/advice')->assertJsonPath('data.kind', 'close')
            ->assertJsonPath('data.waiting', 12)->assertJsonPath('data.spare', 3);

        $this->postJson('/api/v1/delivery-day/advice/dismiss', ['kind' => 'close'])->assertOk();
        $this->getJson('/api/v1/delivery-day/advice')->assertJsonPath('data', null);

        // Désactivé dans les réglages : jamais de conseil
        $this->company->forceFill(['day_advice' => ['enabled' => false], 'day_advice_dismissed' => null])->save();
        Sanctum::actingAs($this->dispatcher->fresh());
        $this->getJson('/api/v1/delivery-day/advice')->assertJsonPath('data', null);

        // Réservé au dispatch
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/delivery-day/advice')->assertForbidden();
    }
}
