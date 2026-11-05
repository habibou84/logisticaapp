<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\OutboundMessage;
use App\Notifications\OrderAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Messages aux e-commerçants : notification « reçue et validée » à la validation automatique, WhatsApp seulement
 * pour les étapes choisies par l'agence, choix laissés ou non aux e-commerçants.
 */
class MerchantMessagesTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();
        $this->buildWorld();
        $this->company->forceFill(['validation_mode' => Company::VALIDATION_AUTO, 'daily_cutoff_time' => null])->save();
        $this->merchant->update(['whatsapp_phone' => '+2250501020304']);
    }

    private function confirmationsSent(): int
    {
        return OutboundMessage::whereIn('template_name', ['course_confirmee', 'course_validee_date'])->count();
    }

    public function test_an_auto_validated_order_is_notified_in_the_app_only_by_default(): void
    {
        $order = $this->createOrder();

        Notification::assertSentTo($this->merchantUser, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'confirmed'
            && $n->title === "✅ Course {$order->tracking_code} reçue et validée"
            && str_contains($n->body, 'Livraison aujourd\'hui pour Jean Kouadio (Yopougon)'));
        $this->assertSame(0, $this->confirmationsSent());

        // L'agence active WhatsApp pour cette étape
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/company/merchant-messages', ['events' => ['order.received' => true]])->assertOk()
            ->assertJsonPath('data.events.0.event', 'order.received')->assertJsonPath('data.events.0.whatsapp', true);
        $this->createOrder();
        $this->assertSame(1, $this->confirmationsSent());
        $this->assertSame('order.received', OutboundMessage::latest('id')->first()->event);
    }

    public function test_the_agency_choice_is_the_default_and_can_be_locked(): void
    {
        // Choix de l'e-commerçant (avant le réglage) : WhatsApp pour « course validée par l'agence »
        Sanctum::actingAs($this->merchantUser);
        $this->putJson("/api/v1/merchants/{$this->merchant->id}/notifications", ['events' => ['order.received' => true]])->assertOk();
        $this->createOrder();
        $this->assertSame(1, $this->confirmationsSent());

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/company/merchant-messages')->assertOk()->assertJsonPath('data.events.0.differs', 1)
            ->assertJsonPath('data.locked', false);

        // Verrouillé : le choix de l'agence s'impose et l'e-commerçant ne peut plus changer
        $this->putJson('/api/v1/company/merchant-messages', ['locked' => true])->assertOk()->assertJsonPath('data.locked', true);
        $this->createOrder();
        $this->assertSame(1, $this->confirmationsSent());
        Sanctum::actingAs($this->merchantUser);
        $this->getJson("/api/v1/merchants/{$this->merchant->id}/notifications")->assertJsonPath('data.locked', true)
            ->assertJsonPath('data.events.0.enabled', false);
        $this->putJson("/api/v1/merchants/{$this->merchant->id}/notifications", ['events' => ['order.received' => true]])->assertStatus(422);

        // Déverrouillé, puis « appliquer à tous » : les choix des e-commerçants sont remplacés
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/company/merchant-messages', ['locked' => false])->assertOk();
        $this->postJson('/api/v1/company/merchant-messages/apply-all', ['event' => 'order.received'])->assertOk()
            ->assertJsonPath('data.removed', 1)->assertJsonPath('data.events.0.differs', 0);
        $this->createOrder();
        $this->assertSame(1, $this->confirmationsSent());

        // Réservé aux réglages
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/company/merchant-messages')->assertForbidden();
    }

    public function test_an_order_validated_by_the_team_keeps_its_own_message(): void
    {
        $this->company->forceFill(['validation_mode' => Company::VALIDATION_MANUAL])->save();
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();

        Notification::assertSentTo($this->merchantUser, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'confirmed'
            && $n->title === "Course {$order->tracking_code} validée");
        $this->assertSame(0, $this->confirmationsSent());
    }
}
