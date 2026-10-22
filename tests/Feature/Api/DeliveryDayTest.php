<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\OutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Validation automatique avec règles : passé l'heure limite, ou journée fermée par le dispatch,
 * les courses du jour sont validées pour le lendemain et le marchand est prévenu.
 */
class DeliveryDayTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->merchant->update(['whatsapp_phone' => '0501020304']);
        $this->company->update(['validation_mode' => 'rules', 'daily_cutoff_time' => '16:00', 'timezone' => 'Africa/Abidjan']);
        $this->travelTo(now('Africa/Abidjan')->setTime(10, 0));
    }

    private function templates(): array
    {
        return OutboundMessage::orderBy('id')->pluck('template_name')->all();
    }

    public function test_before_the_cutoff_orders_are_validated_for_today(): void
    {
        $order = $this->createOrder();

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertNull($order->fresh()->delivery_scheduled_date);
        // Validation simple : message de routine, désactivé par défaut
        $this->assertSame([], $this->templates());
    }

    public function test_after_the_cutoff_orders_are_validated_for_tomorrow_and_the_merchant_is_told(): void
    {
        $this->travelTo(now()->setTime(16, 30));
        $order = $this->createOrder()->fresh();

        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(today()->addDay()->toDateString(), $order->delivery_scheduled_date->toDateString());
        $this->assertStringContainsString('livraisons du jour closes depuis 16:00', $order->events()->latest('id')->first()->note);

        $message = OutboundMessage::sole();
        $this->assertSame('course_validee_date', $message->template_name);
        $this->assertStringContainsString('livraisons du jour closes depuis 16:00', $message->body);

        // Date déjà choisie plus tard : inchangée
        $later = $this->createOrder(['delivery_scheduled_date' => today()->addDays(3)->toDateString()])->fresh();
        $this->assertSame(today()->addDays(3)->toDateString(), $later->delivery_scheduled_date->toDateString());

        // Le ramassage reste pour aujourd'hui
        Sanctum::actingAs($this->dispatcher);
        $this->assertSame('pickup', $this->getJson("/api/v1/orders/{$order->id}")->json('data.awaiting_courier.stage'));
    }

    public function test_without_rules_the_cutoff_changes_nothing(): void
    {
        $this->company->update(['validation_mode' => 'auto']);
        $this->travelTo(now()->setTime(18, 0));

        $order = $this->createOrder()->fresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertNull($order->delivery_scheduled_date);
    }

    public function test_dispatch_closes_the_day_and_postpones_todays_orders(): void
    {
        $this->company->update(['validation_mode' => 'manual']);
        $pending = $this->createOrder();
        $this->company->update(['validation_mode' => 'rules']);
        $confirmed = $this->createOrder();
        $tomorrow = today()->addDay()->toDateString();

        // Les marchands voient l'état, seul le dispatch ferme
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/delivery-day')->assertOk()->assertJsonPath('data.closed', false)->assertJsonPath('data.today_open_count', null);
        $this->postJson('/api/v1/delivery-day/close')->assertForbidden();

        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/delivery-day')->assertJsonPath('data.today_open_count', 2);
        $this->postJson('/api/v1/delivery-day/close', ['postpone' => true])->assertOk()
            ->assertJsonPath('data.closed', true)
            ->assertJsonPath('data.reason', 'manual')
            ->assertJsonPath('data.postponed', 2);

        $this->assertSame(OrderStatus::Confirmed, $pending->fresh()->status);
        $this->assertSame($tomorrow, $pending->fresh()->delivery_scheduled_date->toDateString());
        $this->assertSame($tomorrow, $confirmed->fresh()->delivery_scheduled_date->toDateString());
        // Un message par course décalée
        $this->assertSame(['course_validee_date', 'course_validee_date'], $this->templates());

        // Nouvelle course pendant la fermeture : pour demain (entreprise relue, comme à chaque requête)
        $this->merchant->unsetRelation('company');
        $this->assertSame($tomorrow, $this->createOrder()->fresh()->delivery_scheduled_date->toDateString());

        // Réouverture : de nouveau pour aujourd'hui
        $this->postJson('/api/v1/delivery-day/reopen')->assertOk()->assertJsonPath('data.closed', false);
        $this->merchant->unsetRelation('company');
        $this->assertNull($this->createOrder()->fresh()->delivery_scheduled_date);

        // Fermeture sans report : les courses déjà là gardent leur date
        $kept = $this->createOrder();
        $this->postJson('/api/v1/delivery-day/close')->assertOk()->assertJsonPath('data.postponed', 0);
        $this->merchant->unsetRelation('company');
        $this->assertNull($kept->fresh()->delivery_scheduled_date);

        // Le lendemain, la journée est de nouveau ouverte
        $this->travelTo(now()->addDay()->setTime(9, 0));
        $this->getJson('/api/v1/delivery-day')->assertJsonPath('data.closed', false);
    }

    public function test_closing_requires_the_rules_mode_and_old_setting_follows_the_mode(): void
    {
        $this->company->update(['validation_mode' => 'manual']);
        $this->assertFalse($this->company->fresh()->auto_confirm_orders);

        Sanctum::actingAs($this->dispatcher);
        $this->postJson('/api/v1/delivery-day/close')->assertUnprocessable();

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['validation_mode' => 'rules'])->assertOk()->assertJsonPath('data.validation_mode', 'rules');
        $this->assertTrue($this->company->fresh()->auto_confirm_orders);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['validation_mode' => 'tout'])->assertJsonValidationErrors('validation_mode');

        // Ancien réglage : toujours compris
        $this->company->fresh()->update(['auto_confirm_orders' => false]);
        $this->assertSame(Company::VALIDATION_MANUAL, $this->company->fresh()->validation_mode);
    }
}
