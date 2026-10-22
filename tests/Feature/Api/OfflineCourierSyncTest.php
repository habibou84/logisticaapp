<?php

namespace Tests\Feature\Api;

use App\Models\IncidentReason;
use App\Models\OfflineAction;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Mode hors connexion des livreurs : actions faites sans réseau, envoyées au retour de la connexion.
 */
class OfflineCourierSyncTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    /** Course ramassée et en route avec le livreur A */
    private function outForDelivery(): Order
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierA->id])->assertOk();
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();

        return $order->fresh();
    }

    private function sync(User $courier, array $action)
    {
        Sanctum::actingAs($courier);

        return $this->postJson('/api/v1/courier/sync', $action);
    }

    public function test_an_offline_delivery_is_applied_once_at_its_real_time(): void
    {
        $order = $this->outForDelivery();
        $at = now()->subHours(2)->startOfSecond();
        $action = [
            'id' => 'a1b2c3', 'kind' => 'status', 'order_id' => $order->id, 'occurred_at' => $at->toIso8601String(),
            'payload' => ['status' => 'delivered', 'collected_amount' => $order->cod_amount],
        ];

        $this->sync($this->courierA->user, $action)->assertOk()
            ->assertJsonPath('data.result', 'ok')
            ->assertJsonPath('data.order_status', 'delivered');

        $order->refresh();
        $this->assertSame('delivered', $order->status->value);
        // Heure réelle de la livraison, pas celle de l'envoi
        $this->assertTrue($order->delivered_at->equalTo($at));
        $event = $order->events()->where('to_status', 'delivered')->first();
        $this->assertTrue($event->created_at->equalTo($at));
        $this->assertTrue($event->meta['offline']);

        // Renvoyée (réseau coupé pendant la réponse) : rien n'est fait deux fois
        $this->sync($this->courierA->user, $action)->assertOk()->assertJsonPath('data.result', 'ok');
        $this->assertSame(1, $order->events()->where('to_status', 'delivered')->count());
        $this->assertSame(1, OfflineAction::count());
    }

    public function test_an_implausible_phone_time_falls_back_to_the_server_time(): void
    {
        $order = $this->outForDelivery();

        $this->sync($this->courierA->user, [
            'id' => 'x1', 'kind' => 'status', 'order_id' => $order->id, 'occurred_at' => now()->subDays(10)->toIso8601String(),
            'payload' => ['status' => 'delivered'],
        ])->assertJsonPath('data.result', 'ok');

        $this->assertTrue($order->fresh()->delivered_at->isAfter(now()->subMinute()));
    }

    public function test_a_wrong_client_code_keeps_the_delivery_but_asks_for_a_check(): void
    {
        Notification::fake();
        $this->company->update(['require_delivery_code' => true]);
        $order = $this->outForDelivery();
        $wrong = $order->delivery_code === '0000' ? '1111' : '0000';

        $this->sync($this->courierA->user, [
            'id' => 'c1', 'kind' => 'status', 'order_id' => $order->id, 'occurred_at' => now()->toIso8601String(),
            'payload' => ['status' => 'delivered', 'delivery_code' => $wrong],
        ])->assertJsonPath('data.result', 'ok');

        $order->refresh();
        $this->assertSame('delivered', $order->status->value);
        $this->assertNotNull($order->delivery_code_unverified_at);
        Notification::assertSentTo([$this->admin, $this->dispatcher], OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'delivery_code_unverified');

        // Le dispatch la retrouve, puis la vérifie auprès du destinataire
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/orders?queue=code_to_verify')->assertOk()->assertJsonPath('data.0.id', $order->id);
        $this->postJson("/api/v1/orders/{$order->id}/verify-delivery-code", ['note' => 'Appel au client'])->assertOk()
            ->assertJsonPath('data.delivery_code_unverified_at', null);
        $this->getJson('/api/v1/orders?queue=code_to_verify')->assertOk()->assertJsonCount(0, 'data');

        // En ligne, le code reste exigé
        $other = $this->outForDelivery();
        $this->postJson("/api/v1/orders/{$other->id}/status", ['status' => 'delivered', 'delivery_code' => $other->delivery_code === '0000' ? '1111' : '0000'])
            ->assertUnprocessable();
    }

    public function test_an_action_made_impossible_meanwhile_is_rejected_and_noted_for_dispatch(): void
    {
        $order = $this->outForDelivery();

        // Entre-temps, le dispatch a enregistré un échec de livraison (client joint au téléphone)
        Sanctum::actingAs($this->dispatcher);
        $reason = IncidentReason::where('applies_to', '!=', 'pickup')->where('requires_date', false)->value('id');
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivery_failed', 'incident_reason_id' => $reason])->assertOk();

        $this->sync($this->courierA->user, [
            'id' => 'r1', 'kind' => 'status', 'order_id' => $order->id, 'occurred_at' => now()->toIso8601String(),
            'payload' => ['status' => 'delivered'],
        ])->assertOk()->assertJsonPath('data.result', 'rejected')
            ->assertJsonPath('data.message', 'Transition impossible : « Échec de livraison » → « Livré ».');

        $this->assertSame('delivery_failed', $order->fresh()->status->value);
        $note = $order->events()->where('type', 'note')->latest('id')->first();
        $this->assertStringContainsString('hors connexion refusée', $note->note);
        $this->assertFalse($note->visible_to_merchant);
    }

    public function test_offline_notes_and_invalid_actions(): void
    {
        $order = $this->outForDelivery();

        $this->sync($this->courierA->user, ['id' => 'n1', 'kind' => 'note', 'order_id' => $order->id, 'payload' => ['note' => 'Client absent, voisin prévenu']])
            ->assertJsonPath('data.result', 'ok');
        $this->assertTrue($order->events()->where('note', 'Client absent, voisin prévenu')->exists());

        // Échec sans motif : refusé avec le message de validation
        $this->sync($this->courierA->user, ['id' => 'n2', 'kind' => 'status', 'order_id' => $order->id, 'payload' => ['status' => 'delivery_failed']])
            ->assertJsonPath('data.result', 'rejected')
            ->assertJsonPath('data.message', 'Indiquez le motif de l\'échec.');

        $this->sync($this->courierA->user, ['id' => 'n3', 'kind' => 'status', 'order_id' => 999999, 'payload' => ['status' => 'delivered']])
            ->assertJsonPath('data.result', 'rejected')->assertJsonPath('data.message', 'Course introuvable.');

        // Réservé aux livreurs
        $this->sync($this->dispatcher, ['id' => 'n4', 'kind' => 'note', 'order_id' => $order->id, 'payload' => ['note' => 'x']])->assertForbidden();
    }

    public function test_the_company_setting_is_exposed_to_couriers(): void
    {
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['offline_courier_actions' => true])->assertOk();

        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.company.offline_courier_actions', true);
    }
}
