<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\BillingPlan;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Liste de l'agence sur un jour : chaque onglet ne montre que les courses de ce jour (aujourd'hui, plus celles d'avant
 * encore dans leur règle : lot pas échu, MAD) ; les retards sont dans « Courses non traitées » ; les courses prévues
 * plus tard sont à part (ramassage en avance sur demande) ; les cas à régler restent complets.
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
            // Ramassée hier, toujours pas livrée : non traitée
            'late' => $make(OrderStatus::PickedUp, [], '2026-11-30 10:00:00'),
            // Créée hier, encore à valider : non traitée
            'pending' => $make(OrderStatus::Pending, [], '2026-11-30 11:00:00'),
            'today' => $make(OrderStatus::Confirmed),
            // Ramassage en cours (livreur assigné)
            'picking' => $make(OrderStatus::PickupAssigned),
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

    public function test_all_shows_only_the_chosen_day(): void
    {
        $this->assertEqualsCanonicalizing(['today', 'picking'], $this->names(['day' => self::TODAY]));
        $this->assertEqualsCanonicalizing(['tomorrow'], $this->names(['day' => self::TOMORROW]));

        // Sans jour ou avec une période : comme avant
        $this->assertCount(7, $this->names([]));
        $this->assertSame(['later'], $this->names(['day' => self::TODAY, 'from' => '2026-12-03', 'date_basis' => 'delivery']));
    }

    public function test_work_queues_hold_the_day_and_later_courses_on_request(): void
    {
        $this->assertSame(['picking'], $this->names(['day' => self::TODAY, 'queue' => 'to_pickup']));
        $this->assertSame([], $this->names(['day' => self::TODAY, 'queue' => 'to_deliver']));
        $this->assertSame(['today'], $this->names(['day' => self::TODAY, 'queue' => 'unassigned']));
        $this->assertEqualsCanonicalizing(['today', 'tomorrow'], $this->names(['day' => self::TODAY, 'queue' => 'unassigned', 'include_later' => 1]));
        $this->assertSame(['tomorrow'], $this->names(['day' => self::TOMORROW, 'queue' => 'unassigned']));

        // À valider : seulement le jour choisi ; toutes les dates sans jour
        $this->assertSame([], $this->names(['day' => self::TODAY, 'queue' => 'to_confirm']));
        $this->assertSame(['pending'], $this->names(['queue' => 'to_confirm']));
        // Cas à régler : complets, quel que soit le jour
        $this->assertSame(['later'], $this->names(['day' => self::TODAY, 'queue' => 'incidents']));
        // Retards : dans « Courses non traitées », les plus anciens d'abord
        $this->assertEqualsCanonicalizing(['late', 'pending'], $this->names(['queue' => 'untreated']));
    }

    public function test_distribution_lots_and_mad_stay_in_the_day_until_due(): void
    {
        $lot = BillingPlan::create([
            'company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'formula' => 'distribution_lot', 'activity' => 'distribution',
            'delivery_delay' => '48h', 'starts_on' => '2026-11-20', 'lot_label' => 'Relevés', 'lot_quantity' => 500,
            'lot_price' => 100000, 'lot_due_on' => '2026-12-04',
        ]);
        $make = function (array $data): Order {
            $order = $this->createOrder();
            $order->forceFill(['status' => OrderStatus::PickedUp, 'created_at' => '2026-11-28 10:00:00', ...$data])->saveQuietly();

            return $order;
        };
        $this->o['in_lot'] = $make(['activity' => 'distribution', 'billing_plan_id' => $lot->id]);
        // Distribution sans lot, même dans son délai interne : non traitée dès le lendemain
        $this->o['no_lot'] = $make(['activity' => 'distribution', 'internal_due_at' => '2026-12-05 10:00:00']);
        $this->o['mad'] = $make(['activity' => 'mad']);

        $this->assertEqualsCanonicalizing(['today', 'picking', 'in_lot', 'mad'], $this->names(['day' => self::TODAY]));
        $this->assertEqualsCanonicalizing(['in_lot', 'mad'], $this->names(['day' => self::TODAY, 'queue' => 'to_deliver']));
        $this->assertEqualsCanonicalizing(['late', 'pending', 'no_lot'], $this->names(['queue' => 'untreated']));

        // Le lot arrive à échéance : sa course devient non traitée le lendemain
        $this->travelTo(CarbonImmutable::parse('2026-12-05 09:00', 'UTC'));
        $this->assertContains('in_lot', $this->names(['queue' => 'untreated']));
        $this->assertNotContains('mad', $this->names(['queue' => 'untreated']));

        // L'entreprise met la distribution au jour même : plus d'exception pour le lot
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 09:00', 'UTC'));
        $this->company->update(['delivery_day_rules' => ['distribution' => 'same_day']]);
        $this->assertContains('in_lot', $this->names(['queue' => 'untreated']));
    }

    public function test_counts_and_pickup_groups_follow_the_day(): void
    {
        $counts = $this->getJson('/api/v1/orders/counts?day='.self::TODAY)->assertOk();
        $counts->assertJsonPath('data.day.all', 2)
            ->assertJsonPath('data.day.to_confirm', 0)
            ->assertJsonPath('data.day.to_pickup', 1)
            ->assertJsonPath('data.day.to_deliver', 0)
            ->assertJsonPath('data.day.unassigned', 1)
            ->assertJsonPath('data.day.later.unassigned', 1)
            ->assertJsonPath('data.day.days.today', 2)
            ->assertJsonPath('data.day.days.tomorrow', 1)
            // Compteurs généraux et cas à régler (toutes dates)
            ->assertJsonPath('data.to_pickup', 1)
            ->assertJsonPath('data.exceptions.untreated', 2)
            ->assertJsonPath('data.exceptions.incidents', 1);
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
