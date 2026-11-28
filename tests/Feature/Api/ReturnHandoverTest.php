<?php

namespace Tests\Feature\Api;

use App\Models\IncidentReason;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\ReturnHandover;
use App\Models\User;
use App\Notifications\ReturnHandoverToReview;
use App\Services\WhatsApp\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Remise des colis retournés confirmée par le client : message WhatsApp préparé par le QR code du livreur, mot
 * « retour » et boutons, confirmation dans l'application, remise de secours vérifiée par l'agence.
 */
class ReturnHandoverTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->merchant->update(['whatsapp_phone' => '0501020304']);
        $this->company->update(['return_confirmation' => true]);
        Storage::fake('local');
    }

    private function move(Order $order, User $actor, string $status, array $extra = [])
    {
        Sanctum::actingAs($actor);

        return $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => $status, ...$extra]);
    }

    /** Échec de livraison, retour décidé, colis rendu au dépôt puis confié au livreur A pour le retour */
    private function toReturn(): Order
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        $this->move($order, $this->courierA->user, 'picked_up')->assertOk();
        $this->move($order, $this->courierB->user, 'out_for_delivery')->assertOk();
        $reason = IncidentReason::whereIn('applies_to', ['delivery', 'both'])->where('requires_date', false)->where('triggers_return', false)->orderBy('id')->first();
        $this->move($order, $this->courierB->user, 'delivery_failed', ['incident_reason_id' => $reason->id])->assertOk();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/decision", ['decision' => 'return'])->assertOk();
        $this->postJson("/api/v1/couriers/{$this->courierB->id}/parcels/receive", ['order_ids' => [$order->id]])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'return', 'courier_id' => $this->courierA->id])->assertSuccessful();

        return $order->fresh();
    }

    private function whatsapp(string $text, ?string $button = null): string
    {
        app(Conversation::class)->handle($this->company->fresh(), $this->merchant->fresh()->whatsapp_phone, $button ? 'button' : 'text', $text, $button);

        return (string) OutboundMessage::latest('id')->value('body');
    }

    private function start(): array
    {
        Sanctum::actingAs($this->courierA->user);

        return $this->postJson('/api/v1/courier/return-handovers', ['merchant_id' => $this->merchant->id])->assertOk()->json('data');
    }

    public function test_simple_returned_step_is_refused_when_confirmation_is_required(): void
    {
        $order = $this->toReturn();

        $this->move($order, $this->courierA->user, 'returned')->assertJsonValidationErrors('status');
        $this->assertSame('return_assigned', $order->fresh()->status->value);

        // Réglage coupé : comme avant
        $this->company->update(['return_confirmation' => false]);
        $this->move($order, $this->courierA->user, 'returning')->assertOk();
        $this->move($order, $this->courierA->user, 'returned')->assertOk();
    }

    public function test_merchant_confirms_with_the_prepared_whatsapp_message(): void
    {
        $first = $this->toReturn();
        $second = $this->toReturn();

        $data = $this->start();
        $this->assertSame('pending', $data['status']);
        $this->assertCount(2, $data['orders']);
        $this->assertMatchesRegularExpression('/^R-\d{4}$/', $data['reference']);
        $this->assertSame("RETOUR {$data['reference']} : j'ai bien reçu les 2 colis", $data['message']);

        // Même remise si le livreur rouvre l'écran
        $this->assertSame($data['id'], $this->start()['id']);

        $reply = $this->whatsapp("RETOUR {$data['reference']} : j'ai bien reçu les 2 colis");
        $this->assertStringContainsString('2 colis rendus', $reply);
        $this->assertSame('returned', $first->fresh()->status->value);
        $this->assertSame('returned', $second->fresh()->status->value);

        $handover = ReturnHandover::find($data['id']);
        $this->assertSame(ReturnHandover::CONFIRMED, $handover->status);
        $this->assertSame('whatsapp', $handover->confirmed_via);

        Sanctum::actingAs($this->courierA->user);
        $this->getJson("/api/v1/courier/return-handovers/{$data['id']}")->assertOk()->assertJsonPath('data.status', 'confirmed');
        // Un autre livreur ne voit pas la remise
        Sanctum::actingAs($this->courierB->user);
        $this->getJson("/api/v1/courier/return-handovers/{$data['id']}")->assertForbidden();
    }

    public function test_expired_qr_code_is_refused_but_retour_word_still_works(): void
    {
        $order = $this->toReturn();
        $data = $this->start();
        ReturnHandover::whereKey($data['id'])->update(['expires_at' => now()->subMinute()]);

        $this->assertStringContainsString('expiré', $this->whatsapp("RETOUR {$data['reference']} : j'ai bien reçu le colis"));
        $this->assertSame('return_assigned', $order->fresh()->status->value);

        $ask = $this->whatsapp('Retour');
        $this->assertStringContainsString('Les avez-vous en main', $ask);
        $buttons = OutboundMessage::latest('id')->value('payload')['buttons'];
        $this->assertSame(['Oui, tout reçu', 'Il en manque'], array_values($buttons));

        $this->whatsapp('Oui, tout reçu', "rt:ok:{$data['id']}");
        $this->assertSame('returned', $order->fresh()->status->value);
        $this->assertSame('whatsapp_word', ReturnHandover::find($data['id'])->confirmed_via);
    }

    public function test_merchant_disputes_and_agency_decides(): void
    {
        Notification::fake();
        $order = $this->toReturn();
        $data = $this->start();

        $this->whatsapp('retour');
        $this->assertStringContainsString('agence', $this->whatsapp('Il en manque', "rt:ko:{$data['id']}"));
        $this->assertSame(ReturnHandover::DISPUTED, ReturnHandover::find($data['id'])->status);
        $this->assertSame('return_assigned', $order->fresh()->status->value);
        Notification::assertSentTo($this->dispatcher, ReturnHandoverToReview::class);

        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/return-handovers/review')->assertOk()->assertJsonPath('data.0.id', $data['id']);
        $this->postJson("/api/v1/return-handovers/{$data['id']}/resolve", ['decision' => 'verified'])->assertJsonValidationErrors('decision');
        $this->postJson("/api/v1/return-handovers/{$data['id']}/resolve", ['decision' => 'kept'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('return_assigned', $order->fresh()->status->value);
        $this->getJson('/api/v1/return-handovers/review')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_merchant_confirms_in_the_app(): void
    {
        $order = $this->toReturn();
        $data = $this->start();

        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/return-handovers/mine')->assertOk()->assertJsonPath('data.0.id', $data['id'])
            ->assertJsonMissingPath('data.0.whatsapp_link');
        $this->postJson("/api/v1/return-handovers/{$data['id']}/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertSame('returned', $order->fresh()->status->value);
        $this->assertSame('app', ReturnHandover::find($data['id'])->confirmed_via);

        // Un livreur ne confirme pas à la place du client
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/return-handovers/{$data['id']}/confirm")->assertForbidden();
    }

    public function test_fallback_hand_over_is_reviewed_by_the_agency(): void
    {
        Notification::fake();
        $order = $this->toReturn();
        $data = $this->start();

        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/courier/return-handovers/{$data['id']}/fallback", ['reason' => 'no_phone', 'received_by_name' => 'Awa'])
            ->assertJsonValidationErrors('signature');
        $this->postJson("/api/v1/courier/return-handovers/{$data['id']}/fallback", ['reason' => 'no_phone', 'received_by_name' => 'Awa', 'signature' => self::PNG])
            ->assertOk()->assertJsonPath('data.status', 'unconfirmed')->assertJsonPath('data.has_signature', true);
        $this->assertSame('returned', $order->fresh()->status->value);
        Notification::assertSentTo($this->dispatcher, ReturnHandoverToReview::class);

        // Le client confirme après coup : plus rien à vérifier
        $this->whatsapp('retour');
        $this->whatsapp('Oui, tout reçu', "rt:ok:{$data['id']}");
        $this->assertSame(ReturnHandover::CONFIRMED, ReturnHandover::find($data['id'])->status);
    }

    public function test_courier_without_returns_for_this_merchant_cannot_start(): void
    {
        Sanctum::actingAs($this->courierB->user);
        $this->postJson('/api/v1/courier/return-handovers', ['merchant_id' => $this->merchant->id])->assertJsonValidationErrors('merchant_id');
        $this->assertStringContainsString('Aucun retour', $this->whatsapp('retour'));
    }
}
