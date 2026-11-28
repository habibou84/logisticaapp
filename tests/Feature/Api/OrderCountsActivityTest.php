<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Page Courses filtrée sur une activité : les cases des étapes, les jours et les retards ne comptent que cette
 * activité ; les pastilles du menu (bloc « menu ») restent celles de toute l'entreprise.
 */
class OrderCountsActivityTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const TODAY = '2026-12-01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 09:00', 'UTC'));
        Sanctum::actingAs($this->dispatcher);
    }

    private function make(OrderStatus $status, string $activity, ?string $createdAt = null): Order
    {
        $order = $this->createOrder();
        $order->forceFill([
            'status' => $status,
            'activity' => $activity,
            ...($activity === 'ecommerce' ? [] : ['items_amount' => 0, 'cod_amount' => 0]),
            ...($createdAt ? ['created_at' => $createdAt] : []),
        ])->saveQuietly();

        return $order;
    }

    public function test_counts_follow_the_chosen_activity_and_menu_stays_company_wide(): void
    {
        // E-commerce : deux à valider, une livrée ; distribution : une à valider ; une e-commerce non traitée (hier)
        $this->make(OrderStatus::Pending, 'ecommerce');
        $this->make(OrderStatus::Pending, 'ecommerce');
        $this->make(OrderStatus::Delivered, 'ecommerce');
        $this->make(OrderStatus::Pending, 'distribution');
        $this->make(OrderStatus::PickedUp, 'ecommerce', '2026-11-30 10:00:00');

        $all = $this->getJson('/api/v1/orders/counts?day='.self::TODAY)->assertOk()->json('data');
        $distribution = $this->getJson('/api/v1/orders/counts?day='.self::TODAY.'&activity=distribution')->assertOk()->json('data');
        $ecommerce = $this->getJson('/api/v1/orders/counts?day='.self::TODAY.'&activity=ecommerce')->assertOk()->json('data');

        // Cases des étapes et sélecteur de jour : seulement l'activité choisie
        $this->assertSame(3, $all['day']['to_confirm']);
        $this->assertSame(1, $distribution['day']['to_confirm']);
        $this->assertSame(2, $ecommerce['day']['to_confirm']);
        $this->assertSame(0, $distribution['day']['delivered']);
        $this->assertSame(1, $ecommerce['day']['delivered']);
        $this->assertSame(1, $distribution['day']['days']['today']);
        $this->assertSame(0, $distribution['exceptions']['untreated']);

        // Menu : toute l'entreprise, avec ou sans activité
        $this->assertSame($all['menu'], $distribution['menu']);
        $this->assertSame($all['menu'], $ecommerce['menu']);
        $this->assertSame(1, $all['menu']['untreated']);
        $this->assertSame($all['late']['total'], $all['menu']['late']);

        // Choix de l'activité : courses du jour par activité, toute l'entreprise, avec ou sans activité choisie
        $this->assertSame(1, $all['activities_today']['distribution']);
        $this->assertSame($all['day']['days']['today'], array_sum($all['activities_today']));
        $this->assertSame($all['activities_today'], $distribution['activities_today']);
    }

    public function test_returns_and_reschedule_follow_the_chosen_activity(): void
    {
        // Colis à rendre : un e-commerce, un de distribution
        foreach (['ecommerce', 'distribution'] as $activity) {
            $this->make(OrderStatus::DeliveryFailed, $activity)->forceFill(['return_requested' => true])->saveQuietly();
        }
        // Jamais ramassées, date dépassée : un e-commerce, un de distribution
        foreach (['ecommerce', 'distribution'] as $activity) {
            $this->make(OrderStatus::Confirmed, $activity, '2026-11-29 10:00:00')->forceFill(['delivery_scheduled_date' => '2026-11-29'])->saveQuietly();
        }

        $this->getJson('/api/v1/return-slips/count')->assertOk()->assertJsonPath('data.to_return', 2);
        $this->getJson('/api/v1/return-slips/count?activity=distribution')->assertOk()->assertJsonPath('data.to_return', 1);
        $candidates = fn (string $query) => collect($this->getJson('/api/v1/return-slips'.$query)->assertOk()->json('candidates'))
            ->sum(fn (array $g) => count($g['orders']));
        $this->assertSame(2, $candidates(''));
        $this->assertSame(1, $candidates('?activity=ecommerce'));

        $this->getJson('/api/v1/orders/to-reschedule')->assertOk()->assertJsonPath('data.summary.orders', 2);
        $this->getJson('/api/v1/orders/to-reschedule?activity=distribution')->assertOk()->assertJsonPath('data.summary.orders', 1);
        $this->getJson('/api/v1/orders/to-reschedule?activity=autre')->assertUnprocessable();
    }

    public function test_pickup_groups_follow_the_chosen_activity(): void
    {
        $this->make(OrderStatus::Confirmed, 'ecommerce');
        $this->make(OrderStatus::Confirmed, 'distribution');

        $count = fn (string $query) => collect($this->getJson('/api/v1/orders/pickup-groups'.$query)->assertOk()->json('data'))
            ->sum(fn (array $g) => count($g['orders']));

        $this->assertSame(2, $count(''));
        $this->assertSame(1, $count('?activity=distribution'));
        $this->assertSame(1, $count('?activity=ecommerce'));
    }
}
