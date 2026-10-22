<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\IncidentReason;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Actions du marchand par l'API publique (Jibiat Marchand) : modifier une course, décider après un échec,
 * demander le retour, laisser une note. Mêmes règles que dans l'application du marchand.
 */
class PublicMerchantActionsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        [, $this->key] = ApiKey::issue($this->merchant, 'Jibiat Marchand', ['orders:read', 'orders:write'], $this->merchantUser);
    }

    private function api(string $method, string $uri, array $data = [], ?string $key = null)
    {
        return $this->json($method, '/api/public/v1/'.$uri, $data, ['Authorization' => 'Bearer '.($key ?? $this->key), 'X-Client-App' => 'jibiat-marchand']);
    }

    private function move(Order $order, User $actor, string $status, array $extra = []): void
    {
        Sanctum::actingAs($actor);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => $status, ...$extra])->assertOk();
        $this->app['auth']->forgetGuards();
    }

    private function pickedUp(): Order
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();
        $this->move($order, $this->courierA->user, 'picked_up');

        return $order->fresh();
    }

    private function failed(): Order
    {
        $order = $this->pickedUp();
        $this->move($order, $this->courierB->user, 'out_for_delivery');
        $reason = IncidentReason::where('applies_to', '!=', 'pickup')->where('requires_date', false)->where('triggers_return', false)->first();
        $this->move($order, $this->courierB->user, 'delivery_failed', ['incident_reason_id' => $reason->id]);

        return $order->fresh();
    }

    public function test_the_merchant_edits_everything_before_pickup_then_only_the_delivery(): void
    {
        $order = $this->createOrder(['items_amount' => 10000]);

        $this->api('PATCH', "orders/{$order->tracking_code}", [
            'delivery_address' => 'Cocody Angré, 8e tranche', 'recipient_phone2' => '0101010101', 'items_amount' => 12500,
        ])->assertOk()->assertJsonPath('data.delivery.address', 'Cocody Angré, 8e tranche')->assertJsonPath('data.amounts.items_amount', 12500);
        $this->assertSame('Jibiat Marchand', $order->events()->latest('id')->first()->meta['channel']);

        // Colis récupéré : la livraison reste modifiable, plus le montant
        $picked = $this->pickedUp();
        $this->api('PATCH', "orders/{$picked->tracking_code}", ['delivery_landmark' => 'Portail bleu'])->assertOk();
        $this->api('PATCH', "orders/{$picked->tracking_code}", ['items_amount' => 1])->assertStatus(422);
        $this->api('PATCH', "orders/{$picked->tracking_code}", ['delivery_scheduled_date' => today()->subDay()->toDateString()])->assertJsonValidationErrors('delivery_scheduled_date');

        // Lecture seule ou course d'un autre marchand : refusé
        [, $readOnly] = ApiKey::issue($this->merchant, 'Lecture', ['orders:read'], $this->merchantUser);
        $this->api('PATCH', "orders/{$order->tracking_code}", ['delivery_landmark' => 'x'], $readOnly)->assertForbidden();
        $other = $this->createOrder([], null, $this->otherMerchant());
        $this->api('PATCH', "orders/{$other->tracking_code}", ['delivery_landmark' => 'x'])->assertNotFound();
    }

    public function test_the_merchant_decides_after_a_failed_delivery(): void
    {
        $failed = $this->failed();
        $this->api('GET', "orders/{$failed->tracking_code}")->assertJsonPath('data.needs_decision', true);

        $tomorrow = today()->addDay()->toDateString();
        $this->api('POST', "orders/{$failed->tracking_code}/decision", ['decision' => 'redeliver'])->assertJsonValidationErrors('date');
        $this->api('POST', "orders/{$failed->tracking_code}/decision", ['decision' => 'redeliver', 'date' => $tomorrow, 'note' => 'Le client sera là demain'])
            ->assertOk()->assertJsonPath('data.status', 'rescheduled')->assertJsonPath('data.delivery.scheduled_date', $tomorrow)->assertJsonPath('data.needs_decision', false);
        // Plus de décision attendue
        $this->api('POST', "orders/{$failed->tracking_code}/decision", ['decision' => 'return'])->assertStatus(422);

        // Colis à faire revenir : visible par le dispatch comme une décision du marchand, via Jibiat Marchand
        $other = $this->failed();
        $this->api('POST', "orders/{$other->tracking_code}/decision", ['decision' => 'return', 'note' => 'Client injoignable'])
            ->assertOk()->assertJsonPath('data.return_requested', true);
        Sanctum::actingAs($this->dispatcher);
        $this->getJson("/api/v1/orders/{$other->id}")->assertJsonPath('data.merchant_decision.kind', 'return');
        $this->assertSame('Jibiat Marchand', $other->events()->where('type', 'return_requested')->sole()->meta['channel']);
    }

    public function test_return_request_and_note(): void
    {
        $order = $this->createOrder();
        // Avant le ramassage : on annule la course, pas de retour
        $this->api('POST', "orders/{$order->tracking_code}/return-request")->assertStatus(422);

        $picked = $this->pickedUp();
        $this->api('POST', "orders/{$picked->tracking_code}/return-request", ['note' => 'Le client a annulé'])->assertOk()->assertJsonPath('data.return_requested', true);

        $this->api('POST', "orders/{$order->tracking_code}/notes", ['note' => 'Appeler avant, portail bleu'])->assertCreated();
        $event = $order->events()->where('note', 'Appeler avant, portail bleu')->sole();
        $this->assertSame([true, 'Jibiat Marchand'], [$event->visible_to_merchant, $event->meta['channel']]);
        $this->api('POST', "orders/{$order->tracking_code}/notes", [])->assertJsonValidationErrors('note');
    }

    private function otherMerchant()
    {
        $merchant = $this->merchant->replicate()->fill(['business_name' => 'Autre boutique', 'phone' => '+2250102030499']);
        $merchant->save();

        return $merchant;
    }
}
