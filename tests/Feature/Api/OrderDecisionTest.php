<?php

namespace Tests\Feature\Api;

use App\Models\IncidentReason;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\OrderAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Suite donnée aux colis non livrés : files « À décider » et « Reportées »,
 * relivraison datée, retour au marchand, message au marchand.
 */
class OrderDecisionTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->merchant->update(['whatsapp_phone' => '0501020304']);
    }

    private function move(Order $order, User $actor, string $status, array $extra = [])
    {
        Sanctum::actingAs($actor);

        return $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => $status, ...$extra]);
    }

    private function failed(): Order
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        $this->move($order, $this->courierA->user, 'picked_up')->assertOk();
        $this->move($order, $this->courierB->user, 'out_for_delivery')->assertOk();
        $reason = IncidentReason::where('applies_to', '!=', 'pickup')->where('requires_date', false)->where('triggers_return', false)->first();
        $this->move($order, $this->courierB->user, 'delivery_failed', ['incident_reason_id' => $reason->id])->assertOk();

        return $order->fresh();
    }

    private function queue(string $queue): array
    {
        Sanctum::actingAs($this->dispatcher);

        return array_column($this->getJson("/api/v1/orders?queue={$queue}")->assertOk()->json('data'), 'id');
    }

    public function test_redeliver_later_moves_the_order_between_queues(): void
    {
        $order = $this->failed();
        $this->assertSame([$order->id], $this->queue('to_decide'));
        $this->assertNotContains($order->id, $this->queue('to_deliver'));

        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/decision", ['decision' => 'redeliver'])->assertJsonValidationErrors('date');
        $this->postJson("/api/v1/orders/{$order->id}/decision", ['decision' => 'redeliver', 'date' => today()->subDay()->toDateString()])
            ->assertJsonValidationErrors('date');

        $this->postJson("/api/v1/orders/{$order->id}/decision", [
            'decision' => 'redeliver', 'date' => today()->addDays(2)->toDateString(), 'note' => 'Client en voyage',
        ])->assertOk()
            ->assertJsonPath('data.status', 'rescheduled')
            ->assertJsonPath('data.delivery.scheduled_date', today()->addDays(2)->toDateString());

        $this->assertSame([], $this->queue('to_decide'));
        $this->assertSame([$order->id], $this->queue('scheduled'));
        $this->assertNotContains($order->id, $this->queue('to_deliver'), 'Pas avant la date prévue');

        // Le marchand est prévenu de la nouvelle date
        $message = OutboundMessage::where('template_name', 'incident_livraison')->latest('id')->first();
        $this->assertStringContainsString(today()->addDays(2)->format('d/m/Y'), $message->body);

        // Le jour venu, la course revient dans « À livrer »
        $this->travel(2)->days();
        $this->assertSame([$order->id], $this->queue('to_deliver'));
        $this->assertSame([], $this->queue('scheduled'));

        // Nouvelle date décidée sur un report
        $this->postJson("/api/v1/orders/{$order->id}/decision", ['decision' => 'redeliver', 'date' => today()->addDay()->toDateString()])
            ->assertOk()->assertJsonPath('data.delivery.scheduled_date', today()->addDay()->toDateString());
    }

    public function test_redeliver_today_with_a_courier(): void
    {
        $order = $this->failed();

        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/decision", [
            'decision' => 'redeliver', 'date' => today()->toDateString(), 'courier_id' => $this->courierA->id,
        ])->assertOk()
            ->assertJsonPath('data.status', 'delivery_assigned')
            ->assertJsonPath('data.delivery_courier.id', $this->courierA->id);

        // Mission en cours : plus de décision possible
        $this->postJson("/api/v1/orders/{$order->id}/decision", ['decision' => 'return'])->assertJsonValidationErrors('decision');
    }

    public function test_return_to_merchant(): void
    {
        $order = $this->failed();
        $sent = OutboundMessage::count();

        Sanctum::actingAs($this->merchantUser);
        $this->postJson("/api/v1/orders/{$order->id}/decision", ['decision' => 'return'])->assertForbidden();

        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/decision", ['decision' => 'restock'])->assertJsonValidationErrors('decision');
        $this->postJson("/api/v1/orders/{$order->id}/decision", ['decision' => 'return', 'courier_id' => $this->courierB->id])
            ->assertOk()
            ->assertJsonPath('data.return_requested', true)
            ->assertJsonPath('data.status', 'return_assigned');

        $this->assertSame([], $this->queue('to_decide'));
        $message = OutboundMessage::where('template_name', 'incident_livraison')->latest('id')->first();
        $this->assertSame($sent + 1, OutboundMessage::count());
        $this->assertStringContainsString('le colis vous sera retourné', $message->body);

        // Plus de relivraison possible
        $other = $this->failed();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$other->id}/decision", ['decision' => 'return'])->assertOk()->assertJsonPath('data.status', 'delivery_failed');
        $this->postJson("/api/v1/orders/{$other->id}/decision", ['decision' => 'redeliver', 'date' => today()->toDateString()])
            ->assertJsonValidationErrors('decision');
        $this->assertContains($other->id, $this->queue('to_return'));
    }

    public function test_max_attempts_forbids_redelivery(): void
    {
        $order = $this->failed();
        $order->forceFill(['attempts_count' => $order->max_attempts])->save();

        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/decision", ['decision' => 'redeliver', 'date' => today()->toDateString()])
            ->assertJsonValidationErrors('decision');
    }

    public function test_morning_reminder_for_rescheduled_orders(): void
    {
        Notification::fake();
        $today = $this->failed();
        $later = $this->failed();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$today->id}/decision", ['decision' => 'redeliver', 'date' => today()->addDay()->toDateString()])->assertOk();
        $this->postJson("/api/v1/orders/{$later->id}/decision", ['decision' => 'redeliver', 'date' => today()->addDays(5)->toDateString()])->assertOk();

        // Rien de prévu aujourd'hui
        $this->artisan('orders:due-today')->assertSuccessful();
        $this->assertSame(0, $this->dueTodayAlerts());

        $this->travel(1)->days();
        $this->artisan('orders:due-today')->assertSuccessful();
        Notification::assertSentTo($this->dispatcher, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'due_today'
            && $n->order->is($today) && $n->extra['count'] === 1 && $n->extra['href'] === '/admin/courses?queue=to_deliver'
            && str_contains($n->body, 'chez '.$this->courierB->user->name));

        // Une seule fois par jour
        $this->artisan('orders:due-today');
        $this->assertSame(1, $this->dueTodayAlerts());
    }

    private function dueTodayAlerts(): int
    {
        return Notification::sent($this->dispatcher, OrderAlert::class)->filter(fn (OrderAlert $n) => $n->kind === 'due_today')->count();
    }

    public function test_merchant_decisions_leave_the_to_decide_queue_and_are_shown_to_the_agency(): void
    {
        $tomorrow = today()->addDay()->toDateString();

        // Décision attendue : partout la même règle (agence et marchand)
        $failed = $this->failed();
        Sanctum::actingAs($this->merchantUser);
        $this->getJson("/api/v1/orders/{$failed->id}")->assertJsonPath('data.needs_decision', true)->assertJsonPath('data.merchant_decision', null);
        $this->assertSame([$failed->id], array_column($this->getJson('/api/v1/orders?queue=to_decide')->assertOk()->json('data'), 'id'));

        // Le marchand reporte : plus à décider, et l'agence voit sa décision
        $this->move($failed, $this->merchantUser, 'rescheduled', ['rescheduled_to' => $tomorrow])->assertOk()
            ->assertJsonPath('data.needs_decision', false);
        $this->assertSame([], $this->queue('to_decide'));
        Sanctum::actingAs($this->dispatcher);
        $this->getJson("/api/v1/orders/{$failed->id}")
            ->assertJsonPath('data.merchant_decision.kind', 'redeliver')
            ->assertJsonPath('data.merchant_decision.date', $tomorrow)
            ->assertJsonPath('data.decision_editable', true);
        $this->assertSame('redeliver', collect($this->getJson('/api/v1/orders')->json('data'))->firstWhere('id', $failed->id)['merchant_decision']['kind']);

        // Il peut changer la date tant qu'aucun livreur n'est reparti
        $later = today()->addDays(3)->toDateString();
        $this->move($failed, $this->merchantUser, 'rescheduled', ['rescheduled_to' => $later])->assertOk();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$failed->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierA->id])->assertOk();
        $this->move($failed, $this->merchantUser, 'rescheduled', ['rescheduled_to' => $tomorrow])->assertUnprocessable();

        // Retour demandé par le marchand : plus à décider, mais l'agence le voit
        $other = $this->failed();
        Sanctum::actingAs($this->merchantUser);
        $this->postJson("/api/v1/orders/{$other->id}/return-request")->assertSuccessful();
        $this->getJson("/api/v1/orders/{$other->id}")->assertJsonPath('data.needs_decision', false);
        $this->assertNotContains($other->id, $this->queue('to_decide'));
        Sanctum::actingAs($this->dispatcher);
        $this->getJson("/api/v1/orders/{$other->id}")->assertJsonPath('data.merchant_decision.kind', 'return');
    }
}
