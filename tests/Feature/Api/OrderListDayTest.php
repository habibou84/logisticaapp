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
 * Liste de l'agence sur un jour : les courses du jour et les retards, les files de travail limitées à ce qui
 * est dû, les courses prévues plus tard à part (ramassage en avance sur demande), les exceptions complètes.
 */
class OrderListDayTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const TODAY = '2026-12-01';

    private const TOMORROW = '2026-12-02';

    /** @var array<string, Order> */
    private array $o = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 09:00', 'UTC'));

        $make = function (OrderStatus $status, array $data = [], ?string $createdAt = null): Order {
            $order = $this->createOrder($data);
            $order->forceFill(['status' => $status, ...($createdAt ? ['created_at' => $createdAt] : [])])->saveQuietly();

            return $order;
        };
        $this->o = [
            // Ramassée hier, toujours pas livrée : en retard
            'late' => $make(OrderStatus::PickedUp, [], '2026-11-30 10:00:00'),
            // Validée hier, encore à valider : file d'exception
            'pending' => $make(OrderStatus::Pending, [], '2026-11-30 11:00:00'),
            'today' => $make(OrderStatus::Confirmed),
            'tomorrow' => $make(OrderStatus::Confirmed, ['delivery_scheduled_date' => self::TOMORROW]),
            'delivered' => $make(OrderStatus::Delivered, [], '2026-11-30 12:00:00'),
            'later' => $make(OrderStatus::Rescheduled, ['delivery_scheduled_date' => '2026-12-03']),
        ];
        Sanctum::actingAs($this->dispatcher);
    }

    /** @return list<string> */
    private function names(array $params): array
    {
        $ids = collect($this->getJson('/api/v1/orders?'.http_build_query($params))->assertOk()->json('data'))->pluck('id');
        $names = array_flip(array_map(fn (Order $o) => $o->id, $this->o));

        return $ids->map(fn ($id) => $names[$id])->all();
    }

    public function test_all_shows_the_day_with_late_courses_first(): void
    {
        // Aujourd'hui : la course du jour et les retards encore en cours, les retards en tête
        $today = $this->names(['day' => self::TODAY]);
        $this->assertEqualsCanonicalizing(['late', 'pending', 'today'], $today);
        $this->assertSame('today', end($today));

        // Demain : les courses de demain et les retards d'avant aujourd'hui, pas celles d'aujourd'hui
        $this->assertEqualsCanonicalizing(['late', 'pending', 'tomorrow'], $this->names(['day' => self::TOMORROW]));

        // Sans jour ou avec une période : comme avant
        $this->assertCount(6, $this->names([]));
        $this->assertSame(['later'], $this->names(['day' => self::TODAY, 'from' => '2026-12-03', 'date_basis' => 'delivery']));
    }

    public function test_work_queues_hold_what_is_due_and_later_courses_on_request(): void
    {
        $this->assertSame(['today'], $this->names(['day' => self::TODAY, 'queue' => 'to_pickup']));
        $this->assertEqualsCanonicalizing(['today', 'tomorrow'], $this->names(['day' => self::TODAY, 'queue' => 'to_pickup', 'include_later' => 1]));
        $this->assertSame(['late'], $this->names(['day' => self::TODAY, 'queue' => 'to_deliver']));
        $this->assertSame(['late', 'today'], $this->names(['day' => self::TODAY, 'queue' => 'unassigned']));
        $this->assertEqualsCanonicalizing(['late', 'today', 'tomorrow'], $this->names(['day' => self::TODAY, 'queue' => 'unassigned', 'include_later' => 1]));

        // Demain : la course de demain est à ramasser, celle d'aujourd'hui aussi (toujours pas ramassée)
        $this->assertEqualsCanonicalizing(['today', 'tomorrow'], $this->names(['day' => self::TOMORROW, 'queue' => 'to_pickup']));

        // Files d'exception : complètes, quel que soit le jour
        $this->assertSame(['pending'], $this->names(['day' => self::TOMORROW, 'queue' => 'to_confirm']));
        $this->assertSame(['later'], $this->names(['day' => self::TODAY, 'queue' => 'incidents']));
    }

    public function test_counts_and_pickup_groups_follow_the_day(): void
    {
        $counts = $this->getJson('/api/v1/orders/counts?day='.self::TODAY)->assertOk();
        $counts->assertJsonPath('data.day.all', 3)
            ->assertJsonPath('data.day.to_confirm', 1)
            ->assertJsonPath('data.day.to_pickup', 1)
            ->assertJsonPath('data.day.to_deliver', 1)
            ->assertJsonPath('data.day.unassigned', 2)
            ->assertJsonPath('data.day.later.to_pickup', 1)
            ->assertJsonPath('data.day.later.unassigned', 1)
            ->assertJsonPath('data.day.days.today', 3)
            ->assertJsonPath('data.day.days.tomorrow', 3)
            // Compteurs généraux (menu, tableau de bord) inchangés
            ->assertJsonPath('data.to_pickup', 2);
        $this->getJson('/api/v1/orders/counts')->assertJsonMissingPath('data.day');

        // Ramassages par client : les colis prévus plus tard sont signalés, pas cachés
        $group = $this->getJson('/api/v1/orders/pickup-groups?day='.self::TODAY)->assertOk()->json('data.0');
        $this->assertSame([2, 1], [$group['count'], $group['later_count']]);
        $later = collect($group['orders'])->keyBy('id');
        $this->assertSame([false, true], [$later[$this->o['today']->id]['later'], $later[$this->o['tomorrow']->id]['later']]);
        $this->assertSame(self::TOMORROW, $later[$this->o['tomorrow']->id]['delivery_day']);
        $this->assertSame(0, $this->getJson('/api/v1/orders/pickup-groups')->json('data.0.later_count'));
    }
}
