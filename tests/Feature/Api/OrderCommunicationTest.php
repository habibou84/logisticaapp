<?php

namespace Tests\Feature\Api;

use App\Events\OrderChanged;
use App\Models\IncidentReason;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderAlert;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

class OrderCommunicationTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    private function move(Order $order, string $status, array $extra = [])
    {
        return $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => $status, ...$extra]);
    }

    private function outForDelivery(Order $order): void
    {
        $this->as($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierA->id]);
        $this->as($this->courierA->user);
        $this->move($order, 'picked_up')->assertOk();
        $this->move($order, 'out_for_delivery')->assertOk();
    }

    public function test_new_order_alerts_admins_and_dispatchers_but_not_the_merchant(): void
    {
        Notification::fake();

        $order = $this->createOrder();

        Notification::assertSentTo([$this->admin, $this->dispatcher], OrderAlert::class,
            fn (OrderAlert $n) => $n->kind === 'new_order' && $n->order->is($order));
        Notification::assertNotSentTo($this->merchantUser, OrderAlert::class);
    }

    public function test_assignment_alerts_the_courier(): void
    {
        Notification::fake();
        $order = $this->createOrder();

        $this->as($this->dispatcher)
            ->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);

        Notification::assertSentTo($this->courierA->user, OrderAlert::class,
            fn (OrderAlert $n) => $n->kind === 'new_mission' && $n->title === 'Nouvelle mission : Ramassage');
    }

    public function test_courier_incident_alerts_merchant_and_dispatchers_in_real_time(): void
    {
        $order = $this->createOrder();
        $this->outForDelivery($order);
        Notification::fake();
        Event::fake([OrderChanged::class]);

        $this->move($order, 'delivery_failed', [
            'incident_reason_id' => IncidentReason::where('code', 'unreachable')->value('id'),
            'note' => 'Téléphone éteint',
        ])->assertOk();

        Notification::assertSentTo($this->merchantUser, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'incident'
            && str_contains($n->body, 'Destinataire injoignable')
            && str_contains($n->body, 'Téléphone éteint'));
        Notification::assertSentTo([$this->admin, $this->dispatcher], OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'incident');
        Notification::assertNotSentTo($this->courierA->user, OrderAlert::class);

        Event::assertDispatched(OrderChanged::class, function (OrderChanged $e) use ($order) {
            $channels = array_map(fn (PrivateChannel $c) => $c->name, $e->broadcastOn());

            return $e->order->is($order)
                && $channels === ['private-company.'.$this->company->id, 'private-merchant.'.$this->merchant->id];
        });
    }

    public function test_courier_note_reaches_merchant_and_dispatchers(): void
    {
        $order = $this->createOrder();
        $this->outForDelivery($order);
        Notification::fake();

        $this->postJson("/api/v1/orders/{$order->id}/notes", ['note' => 'Le client demande de rappeler après 17h'])->assertCreated();

        Notification::assertSentTo($this->merchantUser, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'note');
        Notification::assertSentTo($this->dispatcher, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'note');
    }

    public function test_internal_note_is_not_broadcast_to_the_merchant(): void
    {
        $order = $this->createOrder();
        Event::fake([OrderChanged::class]);

        $this->as($this->dispatcher)
            ->postJson("/api/v1/orders/{$order->id}/notes", ['note' => 'interne', 'visible_to_merchant' => false]);

        Event::assertDispatched(OrderChanged::class, fn (OrderChanged $e) => count($e->broadcastOn()) === 1);
    }

    public function test_notification_inbox_and_mark_as_read(): void
    {
        $this->createOrder();
        $this->as($this->dispatcher);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.kind', 'new_order');

        $this->postJson('/api/v1/notifications/read')->assertJsonPath('unread_count', 0);
    }

    public function test_courier_uploads_a_delivery_photo_viewable_by_the_merchant(): void
    {
        Storage::fake('local');
        $order = $this->createOrder();
        $this->outForDelivery($order);

        $url = $this->postJson("/api/v1/orders/{$order->id}/attachments", [
            'file' => UploadedFile::fake()->image('colis.jpg'),
            'type' => 'photo_delivery',
        ])->assertCreated()->json('data.url');

        $this->as($this->merchantUser);
        $this->get($url)->assertOk();
        $proof = collect($this->getJson("/api/v1/orders/{$order->id}")->json('data.events'))->firstWhere('type', 'proof_added');
        $this->assertSame('photo_delivery', $proof['attachments'][0]['type']);

        // Le marchand ne dépose pas de preuve ; un autre livreur ne voit pas la photo
        $this->postJson("/api/v1/orders/{$order->id}/attachments", [
            'file' => UploadedFile::fake()->image('x.jpg'), 'type' => 'document',
        ])->assertForbidden();
        $this->as($this->courierB->user)->get($url)->assertForbidden();
    }

    public function test_only_images_are_accepted_as_proof(): void
    {
        Storage::fake('local');
        $order = $this->createOrder();
        $this->outForDelivery($order);

        $this->postJson("/api/v1/orders/{$order->id}/attachments", [
            'file' => UploadedFile::fake()->create('virus.exe', 10),
            'type' => 'photo_delivery',
        ])->assertJsonValidationErrors('file');
    }

    public function test_public_tracking_shows_progress_without_private_data(): void
    {
        $order = $this->createOrder();
        $this->outForDelivery($order);

        $this->app['auth']->forgetGuards();
        $response = $this->getJson('/api/v1/tracking/'.strtolower($order->tracking_code))
            ->assertOk()
            ->assertJsonPath('data.status', 'out_for_delivery')
            ->assertJsonPath('data.status_label', 'En chemin')
            ->assertJsonPath('data.merchant_name', 'Boutique Test')
            ->assertJsonPath('data.courier_first_name', 'Koffi');

        $json = $response->getContent();
        $this->assertStringNotContainsString('0707070707', $json);
        $this->assertStringNotContainsString('Siporex', $json);
        $this->assertStringNotContainsString((string) $order->delivery_code, collect($response->json('data'))->except('timeline')->toJson());
        $this->assertSame(['pending', 'picked_up', 'out_for_delivery'], array_column($response->json('data.timeline'), 'status'));

        $this->getJson('/api/v1/tracking/LV-XXXX-XXXX')->assertNotFound();
    }

    public function test_merchant_report_summary(): void
    {
        $delivered = $this->createOrder(['items_amount' => 20000]);
        $this->outForDelivery($delivered);
        $this->move($delivered, 'delivered')->assertOk();

        $failed = $this->createOrder();
        $this->outForDelivery($failed);
        $this->move($failed, 'delivery_failed', ['incident_reason_id' => IncidentReason::where('code', 'absent')->value('id')]);

        $this->createOrder(); // en attente

        $this->as($this->merchantUser)->getJson('/api/v1/reports/summary')
            ->assertOk()
            ->assertJsonPath('data.counts.total', 3)
            ->assertJsonPath('data.counts.delivered', 1)
            ->assertJsonPath('data.counts.failed', 1)
            ->assertJsonPath('data.counts.in_progress', 1)
            ->assertJsonPath('data.amounts.collected', 20000)
            ->assertJsonPath('data.amounts.fees', 1500)
            ->assertJsonPath('data.amounts.net_to_merchant', 18500);
    }

    public function test_fees_paid_by_the_recipient_are_kept_from_the_cash_collected(): void
    {
        $order = $this->createOrder(['items_amount' => 15000, 'fee_payer' => 'recipient']);
        $this->outForDelivery($order);
        $this->move($order, 'delivered')->assertOk();

        $this->as($this->merchantUser)->getJson('/api/v1/reports/summary')
            ->assertJsonPath('data.amounts.collected', 16500)
            ->assertJsonPath('data.amounts.fees', 1500)
            ->assertJsonPath('data.amounts.net_to_merchant', 15000);
    }

    public function test_recipients_book_is_filled_automatically(): void
    {
        $this->createOrder(['recipient_name' => 'Jean Kouadio', 'recipient_phone' => '0707070707']);
        $this->createOrder(['recipient_name' => 'Jean K.', 'recipient_phone' => '+225 07 07 07 07 07', 'delivery_landmark' => 'Près de la mosquée']);

        $this->as($this->merchantUser)->getJson('/api/v1/recipients?search=jean')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Jean K.')
            ->assertJsonPath('data.0.landmark', 'Près de la mosquée');
    }

    public function test_private_channels_authorization(): void
    {
        // Pilote Reverb réel (clés factices) pour exercer l'endpoint d'autorisation
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        Broadcast::purge();
        require base_path('routes/channels.php');

        $auth = fn (User $user, string $channel) => $this->withToken($user->createToken('t')->plainTextToken)
            ->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-'.$channel])
            ->status();

        $this->assertSame(200, $auth($this->merchantUser, 'merchant.'.$this->merchant->id));
        $this->app['auth']->forgetGuards();
        $this->assertSame(403, $auth($this->merchantUser, 'merchant.'.($this->merchant->id + 1)));
        $this->app['auth']->forgetGuards();
        $this->assertSame(403, $auth($this->merchantUser, 'company.'.$this->company->id));
        $this->app['auth']->forgetGuards();
        $this->assertSame(200, $auth($this->dispatcher, 'company.'.$this->company->id));
        $this->app['auth']->forgetGuards();
        $this->assertSame(403, $auth($this->courierA->user, 'company.'.$this->company->id));
    }
}
