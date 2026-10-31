<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierAbsence;
use App\Models\CourierRule;
use App\Models\Merchant;
use App\Models\Order;
use App\Notifications\PlanningDigestNotice;
use App\Services\Assignment\CourierPlanning;
use App\Services\Orders\OrderWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Planning des livreurs : qui ramasse où et quand (zones ou clients, tous les jours, certains jours, une date,
 * une période), la règle la plus précise l'emporte, remplaçant si le titulaire est absent ou en pause,
 * ramassage confié automatiquement à la validation (ou noté à l'essai), message du matin.
 */
class CourierPlanningTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    // Lundi
    private const MONDAY = '2026-11-23';

    private Courier $moussa;

    private Merchant $chic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo(CarbonImmutable::parse(self::MONDAY.' 09:00', 'UTC'));
        $this->moussa = $this->userWithRole(Role::Courier, ['name' => 'Moussa Remplaçant'])->courier;
        // En service (bouton « disponible » de l'application du livreur)
        foreach ([$this->courierA, $this->courierB, $this->moussa] as $courier) {
            $courier->update(['is_available' => true]);
        }
        $this->chic = Merchant::factory()->create([
            'company_id' => $this->company->id, 'business_name' => 'Boutique Chic', 'pickup_zone_id' => $this->angre->id, 'pickup_address' => 'Angré 8e tranche',
        ]);
    }

    private function planRule(Courier $courier, array $data): CourierRule
    {
        return CourierRule::create(['company_id' => $this->company->id, 'type' => 'pickup', 'courier_id' => $courier->id, ...$data]);
    }

    private function who(?int $zoneId, ?int $merchantId, string $date = self::MONDAY): ?string
    {
        return app(CourierPlanning::class)->resolveFor($this->company->id, 'pickup', $date, $zoneId, $merchantId)['courier']->user->name ?? null;
    }

    public function test_the_most_precise_rule_wins_and_the_backup_steps_in(): void
    {
        // Cocody tous les jours (couvre Angré) ; Boutique Chic le lundi ; remplacement à Cocody un jour précis
        $this->planRule($this->courierB, ['zone_ids' => [$this->cocody->id], 'backup_courier_id' => $this->moussa->id]);
        $this->planRule($this->courierA, ['merchant_ids' => [$this->chic->id], 'days' => [1]]);
        $this->planRule($this->moussa, ['zone_ids' => [$this->cocody->id], 'starts_on' => '2026-11-25', 'ends_on' => '2026-11-25']);

        $this->assertSame('Awa Livreuse', $this->who($this->cocody->id, $this->merchant->id));
        $this->assertSame('Awa Livreuse', $this->who($this->angre->id, null), 'une commune couvre ses quartiers');
        $this->assertSame('Koffi Ramasseur', $this->who($this->angre->id, $this->chic->id), 'un client avant sa zone');
        $this->assertSame('Awa Livreuse', $this->who($this->angre->id, $this->chic->id, '2026-11-24'), 'la règle du client ne vaut que le lundi');
        $this->assertSame('Moussa Remplaçant', $this->who($this->cocody->id, $this->merchant->id, '2026-11-25'), 'une date avant tous les jours');
        $this->assertNull($this->who($this->yopougon->id, null));

        // Absente ou en pause : le remplaçant ; lui aussi absent : personne
        CourierAbsence::create(['company_id' => $this->company->id, 'courier_id' => $this->courierB->id, 'starts_on' => self::MONDAY, 'ends_on' => self::MONDAY]);
        $this->assertSame('Moussa Remplaçant', $this->who($this->cocody->id, $this->merchant->id));
        $this->moussa->update(['is_available' => false]);
        $this->assertNull($this->who($this->cocody->id, $this->merchant->id));
        $this->assertSame('Awa Livreuse', $this->who($this->cocody->id, $this->merchant->id, '2026-11-24'));
    }

    public function test_validated_courses_go_to_the_planned_courier_or_are_noted_on_trial(): void
    {
        $this->planRule($this->courierB, ['zone_ids' => [$this->cocody->id]]);
        $workflow = app(OrderWorkflow::class);

        // Désactivé : rien ne change
        $off = $this->createOrder();
        $workflow->transition($this->dispatcher, $off, OrderStatus::Confirmed);
        $this->assertSame(OrderStatus::Confirmed, $off->fresh()->status);

        // Essai : rien n'est envoyé, le choix est noté
        $this->company->update(['auto_pickup' => Company::AUTO_TRIAL]);
        $trial = $this->createOrder();
        $workflow->transition($this->dispatcher, $trial, OrderStatus::Confirmed);
        $this->assertSame(OrderStatus::Confirmed, $trial->fresh()->status);
        $this->assertStringContainsString('aurait été confié à Awa Livreuse', $trial->events()->where('type', 'note')->sole()->note);

        // Actif : ramassage confié, expliqué sur la course
        $this->company->update(['auto_pickup' => Company::AUTO_ON]);
        $on = $this->createOrder();
        $workflow->transition($this->dispatcher, $on, OrderStatus::Confirmed);
        $on->refresh();
        $this->assertSame([OrderStatus::PickupAssigned, $this->courierB->id], [$on->status, $on->pickup_courier_id]);
        $this->assertSame('planning', $on->assignments()->sole()->source);
        $this->assertStringContainsString('Ramassage confié automatiquement à Awa Livreuse, planning « Cocody · tous les jours »', $on->events()->where('type', 'note')->latest('id')->first()->note);

        // Rattrapage : la course validée avant l'activation part au passage suivant
        $this->artisan('assignments:auto')->assertSuccessful();
        $this->assertSame(OrderStatus::PickupAssigned, $off->fresh()->status);

        // Sans règle pour la zone : la course reste à l'équipe
        $other = Merchant::factory()->create(['company_id' => $this->company->id, 'pickup_zone_id' => $this->yopougon->id, 'pickup_address' => 'Siporex']);
        $this->rule($this->grid, $this->yopougon, $this->yopougon, 1000);
        $alone = $this->createOrder([], null, $other);
        $workflow->transition($this->dispatcher, $alone, OrderStatus::Confirmed);
        $this->assertSame(OrderStatus::Confirmed, $alone->fresh()->status);
    }

    public function test_the_planning_screen_rules_conflicts_and_gaps(): void
    {
        Sanctum::actingAs($this->dispatcher);
        $this->createOrder()->forceFill(['status' => OrderStatus::Confirmed])->save();

        // Boutique Test (Cocody) sans personne : signalée avec sa course en attente
        $gaps = $this->getJson('/api/v1/planning')->assertOk()->json('data.gaps');
        $this->assertSame([$this->merchant->business_name], array_column($gaps[0]['merchants'], 'name'));
        $this->assertSame(1, $gaps[0]['waiting']);

        $rule = $this->postJson('/api/v1/planning/rules', [
            'type' => 'pickup', 'courier_id' => $this->courierB->id, 'backup_courier_id' => $this->moussa->id,
            'zone_ids' => [$this->cocody->id], 'days' => [1, 3, 5],
        ])->assertCreated()->assertJsonPath('data.label', 'Cocody · lundi, mercredi, vendredi')->json('data');

        // Même zone, mêmes jours, autre livreur : refusé ; une date précise (remplacement) : accepté
        $this->postJson('/api/v1/planning/rules', ['type' => 'pickup', 'courier_id' => $this->courierA->id, 'zone_ids' => [$this->cocody->id], 'days' => [1]])
            ->assertStatus(422)->assertJsonPath('errors.courier_id.0', fn ($m) => str_contains($m, 'Awa Livreuse'));
        $this->postJson('/api/v1/planning/rules', ['type' => 'pickup', 'courier_id' => $this->courierA->id, 'zone_ids' => [$this->cocody->id], 'starts_on' => '2026-11-25', 'ends_on' => '2026-11-25'])
            ->assertCreated()->assertJsonPath('data.when', 'le 25/11/2026');
        $this->postJson('/api/v1/planning/rules', ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertJsonValidationErrors(['zone_ids', 'merchant_ids']);

        $this->postJson('/api/v1/planning/absences', ['courier_id' => $this->courierB->id, 'starts_on' => self::MONDAY, 'ends_on' => '2026-11-24', 'reason' => 'Congé'])->assertCreated();
        $day = $this->getJson('/api/v1/planning?date='.self::MONDAY)->json('data');
        $mine = collect($day['rules'])->firstWhere('id', $rule['id']);
        $this->assertSame([true, 'backup'], [$mine['applies'], $mine['covering']]);
        $this->assertSame([], $day['gaps']);
        $this->assertTrue($day['absences'][0]['current']);
        $this->assertFalse(collect($this->getJson('/api/v1/planning?date=2026-11-24')->json('data.rules'))->firstWhere('id', $rule['id'])['applies']);

        $this->deleteJson("/api/v1/planning/rules/{$rule['id']}")->assertNoContent();
        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/planning')->assertForbidden();
    }

    public function test_each_courier_gets_the_morning_message_once(): void
    {
        Notification::fake();
        $this->planRule($this->courierB, ['zone_ids' => [$this->cocody->id], 'backup_courier_id' => $this->moussa->id]);
        $this->planRule($this->courierA, ['merchant_ids' => [$this->chic->id]]);
        CourierAbsence::create(['company_id' => $this->company->id, 'courier_id' => $this->courierA->id, 'starts_on' => self::MONDAY, 'ends_on' => self::MONDAY]);

        $this->travelTo(CarbonImmutable::parse(self::MONDAY.' 06:00', 'UTC'));
        $this->artisan('couriers:planning-digest')->assertSuccessful();
        Notification::assertNothingSent();

        $this->travelTo(CarbonImmutable::parse(self::MONDAY.' 07:00', 'UTC'));
        $this->artisan('couriers:planning-digest')->assertSuccessful();
        $this->artisan('couriers:planning-digest')->assertSuccessful();
        Notification::assertSentToTimes($this->courierB->user, PlanningDigestNotice::class, 1);
        Notification::assertSentTo($this->courierB->user, PlanningDigestNotice::class, fn (PlanningDigestNotice $n) => $n->lines === ['Ramassage : Cocody']);
        // Koffi absent : rien ; personne ne remplace Boutique Chic
        Notification::assertNotSentTo($this->courierA->user, PlanningDigestNotice::class);
        Notification::assertNotSentTo($this->moussa->user, PlanningDigestNotice::class);
        $this->assertNotNull(Order::query()->getModel());
    }

    public function test_gaps_only_list_clients_with_parcels_for_that_day(): void
    {
        Sanctum::actingAs($this->dispatcher);
        $later = $this->createOrder();
        $later->forceFill(['status' => OrderStatus::Confirmed, 'delivery_scheduled_date' => now()->addDays(2)->toDateString()])->save();

        // Aujourd'hui : pas de colis pour aujourd'hui, le client est seulement « habituel »
        $gaps = $this->getJson('/api/v1/planning')->assertOk()->json('data.gaps');
        $this->assertSame([], $gaps[0]['merchants']);
        $this->assertSame([$this->merchant->business_name], array_column($gaps[0]['others'], 'name'));

        // Le jour de livraison du colis : il attend un ramasseur
        $day = $this->getJson('/api/v1/planning?date='.now()->addDays(2)->toDateString())->json('data.gaps');
        $this->assertSame(1, $day[0]['waiting']);
        $this->assertSame([1], array_column($day[0]['merchants'], 'waiting'));
        $this->assertArrayHasKey('usual', $day[0]['merchants'][0]);
    }
}
