<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Notifications\OrderAlert;
use App\Services\Orders\OrderWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Listes et points par jour de livraison (date prévue, sinon jour de création), sans toucher aux files
 * ni aux alertes : le ramassage reste dû dès la validation.
 */
class DeliveryDayListsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo(CarbonImmutable::parse('2026-12-01 09:00', 'UTC'));
    }

    /** @return array{forTomorrow: Order, fromYesterday: Order, undated: Order} */
    private function orders(): array
    {
        $fromYesterday = $this->createOrder(['delivery_scheduled_date' => '2026-12-01']);
        $fromYesterday->forceFill(['created_at' => '2026-11-30 10:00:00'])->save();

        return [
            'forTomorrow' => $this->createOrder(['delivery_scheduled_date' => '2026-12-02']),
            'fromYesterday' => $fromYesterday,
            'undated' => $this->createOrder(),
        ];
    }

    private function ids(array $params): array
    {
        return collect($this->getJson('/api/v1/orders?'.http_build_query($params))->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    public function test_lists_and_points_follow_the_delivery_day_on_request(): void
    {
        ['forTomorrow' => $tomorrow, 'fromYesterday' => $yesterday, 'undated' => $undated] = $this->orders();
        Sanctum::actingAs($this->merchantUser);
        $today = ['from' => '2026-12-01', 'to' => '2026-12-01'];

        // Par défaut (et pour l'API publique) : jour de création, comme avant
        $this->assertSame(collect([$tomorrow->id, $undated->id])->sort()->values()->all(), $this->ids($today));
        // Jour de livraison : la course d'hier pour aujourd'hui est là, celle de demain attend son jour
        $this->assertSame(collect([$yesterday->id, $undated->id])->sort()->values()->all(), $this->ids([...$today, 'date_basis' => 'delivery']));
        $this->assertSame([$tomorrow->id], $this->ids(['from' => '2026-12-02', 'date_basis' => 'delivery']));

        $this->getJson('/api/v1/reports/summary?'.http_build_query([...$today, 'date_basis' => 'delivery']))->assertOk()
            ->assertJsonPath('data.counts.total', 2)->assertJsonPath('data.period.date_basis', 'delivery');
        $this->getJson('/api/v1/reports/summary?'.http_build_query($today))->assertJsonPath('data.counts.total', 2)
            ->assertJsonPath('data.period.date_basis', 'created');
        $this->getJson('/api/v1/orders?date_basis=autre')->assertJsonValidationErrors('date_basis');

        // Reportée de lundi à mercredi : elle quitte le lundi et passe au mercredi
        $undated->forceFill(['status' => OrderStatus::Rescheduled, 'delivery_scheduled_date' => '2026-12-03'])->save();
        $this->assertSame([$yesterday->id], $this->ids([...$today, 'date_basis' => 'delivery']));
        $this->assertSame([$undated->id], $this->ids(['from' => '2026-12-03', 'to' => '2026-12-03', 'date_basis' => 'delivery']));
    }

    public function test_courses_planned_later_are_listed_before_pickup_too(): void
    {
        ['forTomorrow' => $tomorrow] = $this->orders();
        app(OrderWorkflow::class)->transition($this->dispatcher, $tomorrow, OrderStatus::Confirmed);
        Sanctum::actingAs($this->dispatcher);

        $this->assertSame([$tomorrow->id], $this->ids(['queue' => 'scheduled']));
        // Le ramassage reste dû aujourd'hui
        $this->assertContains($tomorrow->id, $this->ids(['queue' => 'to_pickup']));
    }

    public function test_the_cutoff_separates_pickups_for_another_day(): void
    {
        $this->company->update(['daily_cutoff_time' => '15:00', 'pickup_assign_alert_minutes' => 0, 'delivery_assign_alert_minutes' => 0]);
        ['forTomorrow' => $tomorrow, 'undated' => $undated] = $this->orders();
        foreach ([$tomorrow, $undated] as $order) {
            app(OrderWorkflow::class)->transition($this->dispatcher, $order, OrderStatus::Confirmed);
        }
        Notification::fake();
        Sanctum::actingAs($this->dispatcher);

        $this->travelTo(CarbonImmutable::parse('2026-12-01 15:05', 'UTC'));
        $this->getJson('/api/v1/orders/counts')->assertJsonPath('data.cutoff.count', 1)->assertJsonPath('data.cutoff.later', 1);
        $this->artisan('orders:unassigned')->assertSuccessful();

        Notification::assertSentTo($this->admin, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'unassigned_cutoff'
            && $n->title === '⏰ 15:00 passées : 1 course du jour toujours sans livreur'
            && str_contains($n->body, 'Et 1 à ramasser pour un autre jour.')
            && ! str_contains(strstr($n->body, 'Elles risquent', true), $tomorrow->tracking_code));
    }

    public function test_the_morning_reminder_includes_parcels_picked_up_early(): void
    {
        Notification::fake();
        $early = $this->createOrder(['delivery_scheduled_date' => '2026-12-02']);
        $early->forceFill(['status' => OrderStatus::PickedUp])->save();

        $this->artisan('orders:due-today')->assertSuccessful();
        Notification::assertNotSentTo($this->dispatcher, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'due_today');

        $this->travelTo(CarbonImmutable::parse('2026-12-02 06:50', 'UTC'));
        $this->artisan('orders:due-today')->assertSuccessful();
        Notification::assertSentTo($this->dispatcher, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'due_today'
            && $n->title === "📅 Course {$early->tracking_code} prévue aujourd'hui (colis déjà ramassé)");
    }
}
