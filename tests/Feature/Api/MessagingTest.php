<?php

namespace Tests\Feature\Api;

use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Models\Company;
use App\Models\IncidentReason;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\ScheduledReport;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->merchant->update(['whatsapp_phone' => '0501020304']);
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

    private function outForDelivery(Order $order): Order
    {
        $this->as($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        $this->as($this->courierA->user)->move($order, 'picked_up')->assertOk();
        $this->as($this->courierB->user)->move($order, 'out_for_delivery')->assertOk();

        return $order->fresh();
    }

    /**
     * @return array<int, string>
     */
    private function templatesSent(): array
    {
        return OutboundMessage::orderBy('id')->pluck('template_name')->all();
    }

    private function useMeta(): WhatsAppAccount
    {
        config(['messaging.whatsapp.driver' => 'meta']);

        return WhatsAppAccount::create([
            'company_id' => $this->company->id,
            'owner_type' => 'company',
            'phone_number_id' => '1098765',
            'waba_id' => '5550001',
            'access_token' => 'secret-token',
            'status' => 'connected',
        ]);
    }

    public function test_recipient_gets_code_and_merchant_is_told_of_the_delivery(): void
    {
        $order = $this->outForDelivery($this->createOrder(['items_amount' => 12000]));

        $toRecipient = OutboundMessage::where('recipient_type', 'recipient')->sole();
        $this->assertSame('colis_en_route', $toRecipient->template_name);
        $this->assertSame('+2250707070707', $toRecipient->to);
        $this->assertSame(MessageStatus::Sent, $toRecipient->status);
        $this->assertSame($order->delivery_code, $toRecipient->payload[3]);
        $this->assertStringContainsString('12 000 F', $toRecipient->body);
        $this->assertStringContainsString(url('/suivi/'.$order->tracking_code), $toRecipient->body);

        $this->move($order, 'delivered')->assertOk();

        $delivered = OutboundMessage::where('template_name', 'colis_livre')->sole();
        $this->assertSame('+2250501020304', $delivered->to);
        $this->assertSame($order->id, $delivered->order_id);
        $this->assertStringContainsString('12 000 F', $delivered->body);

        // Validation et ramassage : désactivés par défaut
        $this->assertSame(['colis_en_route', 'colis_livre'], $this->templatesSent());
    }

    public function test_merchant_preferences_choose_the_messages(): void
    {
        $this->as($this->merchantUser)
            ->putJson("/api/v1/merchants/{$this->merchant->id}/notifications", [
                'events' => ['order.picked_up' => true, 'order.delivered' => false],
            ])
            ->assertOk()
            ->assertJsonPath('data.events.1.event', 'order.picked_up')
            ->assertJsonPath('data.events.1.enabled', true)
            ->assertJsonPath('data.events.2.enabled', false);

        $order = $this->outForDelivery($this->createOrder());
        $this->move($order, 'delivered')->assertOk();

        $this->assertSame(['colis_recupere', 'colis_en_route'], $this->templatesSent());
    }

    public function test_incident_alerts_the_merchant_with_the_reason(): void
    {
        $order = $this->outForDelivery($this->createOrder());

        $this->move($order, 'delivery_failed', [
            'incident_reason_id' => IncidentReason::where('code', 'unreachable')->value('id'),
            'note' => "Appelé\n3 fois",
        ])->assertOk();

        $incident = OutboundMessage::where('template_name', 'incident_livraison')->sole();
        $this->assertSame('livraison impossible, Destinataire injoignable, Appelé 3 fois', $incident->payload[3]);
        $this->assertSame(url('/marchand/courses/'.$order->id), $incident->payload[4]);
    }

    public function test_recipient_messages_can_be_turned_off(): void
    {
        $this->company->update(['notify_recipients' => false]);

        $this->outForDelivery($this->createOrder());

        $this->assertSame(0, OutboundMessage::where('recipient_type', 'recipient')->count());
    }

    public function test_meta_receives_the_template_and_parameters(): void
    {
        $this->useMeta();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC']]])]);

        $this->outForDelivery($this->createOrder());

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://graph.facebook.com/v21.0/1098765/messages'
                && $request->hasHeader('Authorization', 'Bearer secret-token')
                && $request['to'] === '2250707070707'
                && $request['template']['name'] === 'colis_en_route'
                && $request['template']['language']['code'] === 'fr'
                && count($request['template']['components'][0]['parameters']) === 5;
        });

        $message = OutboundMessage::sole();
        $this->assertSame('wamid.ABC', $message->provider_message_id);
        $this->assertSame(MessageStatus::Sent, $message->status);
    }

    public function test_number_without_whatsapp_falls_back_to_sms(): void
    {
        $this->useMeta();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => [
            'code' => 131026, 'message' => 'Message undeliverable',
        ]], 400)]);

        $this->outForDelivery($this->createOrder());

        $whatsapp = OutboundMessage::where('channel', 'whatsapp')->sole();
        $this->assertSame(MessageStatus::Failed, $whatsapp->status);
        $this->assertStringContainsString('131026', $whatsapp->error);

        $sms = OutboundMessage::where('channel', 'sms')->sole();
        $this->assertSame($whatsapp->id, $sms->fallback_for_id);
        $this->assertSame($whatsapp->body, $sms->body);
        $this->assertSame(MessageStatus::Sent, $sms->status);
    }

    public function test_temporary_errors_are_retried_and_sms_can_be_disabled(): void
    {
        $this->useMeta();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 130429, 'message' => 'Rate limit']], 429)]);

        $this->outForDelivery($this->createOrder());

        // Remis en file pour une nouvelle tentative, sans repli
        $message = OutboundMessage::sole();
        $this->assertSame(MessageStatus::Queued, $message->status);
        $this->assertSame(1, $message->attempts);
        $this->assertStringContainsString('Rate limit', $message->error);

        $this->company->update(['sms_fallback' => false]);
        config(['messaging.whatsapp.driver' => 'meta']);
        WhatsAppAccount::query()->update(['access_token' => null]);
        $this->as($this->courierB->user)->move($message->order, 'delivered')->assertOk();

        $failed = OutboundMessage::where('template_name', 'colis_livre')->sole();
        $this->assertSame(MessageStatus::Failed, $failed->status);
        $this->assertStringContainsString('non configuré', $failed->error);
        $this->assertSame(0, OutboundMessage::where('channel', 'sms')->count());
    }

    public function test_webhook_updates_delivery_status_and_falls_back_on_failure(): void
    {
        config(['messaging.whatsapp.app_secret' => 'app-secret']);
        $this->outForDelivery($this->createOrder());
        $message = OutboundMessage::sole();
        $message->update(['provider_message_id' => 'wamid.XYZ']);

        $post = function (array $statuses, ?string $secret = 'app-secret') {
            $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => ['statuses' => $statuses]]]]]]);

            return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
            ], $body);
        };

        $post([['id' => 'wamid.XYZ', 'status' => 'delivered']], 'wrong')->assertForbidden();

        $post([['id' => 'wamid.XYZ', 'status' => 'read'], ['id' => 'wamid.XYZ', 'status' => 'delivered']])->assertOk();
        $message->refresh();
        $this->assertSame(MessageStatus::Read, $message->status);
        $this->assertNotNull($message->read_at);

        // Un échec signalé après coup déclenche le SMS de repli
        $message->update(['status' => MessageStatus::Sent]);
        $post([['id' => 'wamid.XYZ', 'status' => 'failed', 'errors' => [['code' => 131026, 'title' => 'Undeliverable']]]])->assertOk();
        $this->assertSame(MessageStatus::Failed, $message->fresh()->status);
        $this->assertSame(1, OutboundMessage::where('fallback_for_id', $message->id)->count());

        // Doublon : pas de second SMS
        $post([['id' => 'wamid.XYZ', 'status' => 'failed']])->assertOk();
        $this->assertSame(1, OutboundMessage::where('fallback_for_id', $message->id)->count());
    }

    public function test_webhook_subscription_check(): void
    {
        config(['messaging.whatsapp.verify_token' => 'verif']);

        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verif&hub.challenge=12345')
            ->assertOk()->assertSee('12345');
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=bad&hub.challenge=12345')
            ->assertForbidden();
    }

    public function test_paid_payout_is_announced(): void
    {
        $cashier = $this->userWithRole(Role::Cashier);
        $order = $this->outForDelivery($this->createOrder(['items_amount' => 10000]));
        $this->move($order, 'delivered', ['received_by_company' => true, 'payment_method' => 'wave'])->assertOk();

        $this->as($cashier);
        $payout = $this->postJson('/api/v1/finance/payouts', ['merchant_id' => $this->merchant->id])->assertCreated()->json('data');
        $this->postJson("/api/v1/finance/payouts/{$payout['id']}/pay", ['method' => 'wave'])->assertOk();

        $message = OutboundMessage::where('template_name', 'reversement_effectue')->sole();
        $this->assertSame([$this->merchant->business_name, $payout['reference'], '8 500 F', 'Wave'], $message->payload);
    }

    public function test_scheduled_reports_are_sent_once_when_due(): void
    {
        // Activation avant l'heure d'envoi (le test ne dépend pas de l'heure réelle)
        Carbon::setTestNow('2026-10-08 17:00:00');
        $this->as($this->merchantUser)->putJson("/api/v1/merchants/{$this->merchant->id}/notifications", [
            'reports' => [
                'daily' => ['active' => true, 'send_time' => '19:00'],
                'weekly' => ['active' => true, 'send_time' => '09:00', 'weekday' => 1],
            ],
        ])->assertOk()->assertJsonPath('data.reports.daily.active', true);

        // Le jour de l'activation à 18 h : rien n'est encore dû
        Carbon::setTestNow('2026-10-08 18:00:00');
        $this->createOrder();
        $this->artisan('reports:send')->expectsOutput('0 rapport(s) envoyé(s).');

        Carbon::setTestNow('2026-10-08 19:02:00');
        $this->artisan('reports:send')->expectsOutput('1 rapport(s) envoyé(s).');
        $this->artisan('reports:send')->expectsOutput('0 rapport(s) envoyé(s).');

        $report = OutboundMessage::where('template_name', 'rapport_activite')->sole();
        $this->assertSame('report.daily', $report->event);
        $this->assertSame('du 08/10/2026', $report->payload[1]);
        $this->assertSame('1', $report->payload[2]);

        // Lundi suivant : rapport hebdomadaire de la semaine écoulée, et quotidien vide (non envoyé)
        Carbon::setTestNow('2026-10-12 19:30:00');
        $this->artisan('reports:send')->expectsOutput('1 rapport(s) envoyé(s).');
        $weekly = OutboundMessage::where('event', 'report.weekly')->sole();
        $this->assertSame('du 05/10 au 11/10/2026', $weekly->payload[1]);
        $this->assertNotNull(ScheduledReport::where('frequency', 'daily')->value('last_sent_at'));

        Carbon::setTestNow();
    }

    public function test_whatsapp_settings_are_managed_by_the_admin(): void
    {
        $this->as($this->merchantUser)->getJson('/api/v1/whatsapp/settings')->assertForbidden();
        $this->as($this->dispatcher)->getJson('/api/v1/whatsapp/settings')->assertForbidden();

        // Meta confirme le numéro et l'abonnement aux messages reçus
        Http::fake([
            '*/1234?*' => Http::response(['display_phone_number' => '+225 07 00 00 00 99', 'verified_name' => 'Rapide Express', 'quality_rating' => 'GREEN']),
            '*/987/subscribed_apps' => Http::response(['success' => true]),
        ]);

        $this->as($this->admin)->putJson('/api/v1/whatsapp/settings', [
            'notify_recipients' => false,
            'account' => ['display_phone' => '0700000099', 'phone_number_id' => '1234', 'waba_id' => '987', 'access_token' => 'tok'],
        ])->assertOk()
            ->assertJsonPath('data.notify_recipients', false)
            ->assertJsonPath('data.account.status', 'connected')
            ->assertJsonPath('data.account.verified_name', 'Rapide Express')
            ->assertJsonPath('data.account.quality_rating', 'green')
            ->assertJsonPath('data.account.has_access_token', true)
            ->assertJsonMissingPath('data.account.access_token')
            ->assertJsonPath('data.templates.2.name', 'colis_en_route');

        // Jeton conservé quand le champ est laissé vide
        $this->putJson('/api/v1/whatsapp/settings', ['account' => ['phone_number_id' => '1234', 'access_token' => '']])
            ->assertJsonPath('data.account.has_access_token', true);
        $this->assertSame('tok', WhatsAppAccount::sole()->access_token);

        $this->postJson('/api/v1/whatsapp/templates/sync')->assertOk()->assertJsonPath('data.templates.0.status', 'approved');

        $this->postJson('/api/v1/whatsapp/test', ['to' => '0700000099'])->assertCreated()
            ->assertJsonPath('data.template_name', 'hello_world')
            ->assertJsonPath('data.status', 'sent');
    }

    public function test_message_log_and_manual_retry(): void
    {
        $order = $this->outForDelivery($this->createOrder());
        $message = OutboundMessage::sole();
        $message->update(['status' => MessageStatus::Failed, 'error' => 'boom']);

        $this->as($this->merchantUser)->getJson('/api/v1/messages')->assertForbidden();
        $this->getJson("/api/v1/orders/{$order->id}/messages")->assertForbidden();

        $this->as($this->dispatcher)->getJson('/api/v1/messages?status=failed')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order.tracking_code', $order->tracking_code);

        $this->postJson("/api/v1/messages/{$message->id}/retry")->assertCreated()->assertJsonPath('data.status', 'sent');
        $this->postJson("/api/v1/messages/{$message->id}/retry")->assertCreated();
        $this->getJson("/api/v1/orders/{$order->id}/messages")->assertOk()->assertJsonCount(3, 'data');

        $sent = OutboundMessage::where('status', 'sent')->first();
        $this->postJson("/api/v1/messages/{$sent->id}/retry")->assertUnprocessable();
    }

    public function test_messages_of_another_company_are_invisible(): void
    {
        $this->outForDelivery($this->createOrder());
        $messageId = OutboundMessage::sole()->id;
        $other = Company::factory()->create();
        $outsider = $this->userWithRole(Role::Dispatcher, [], $other);

        $this->as($outsider)->getJson('/api/v1/messages')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/messages/{$messageId}/retry")->assertNotFound();
        $this->getJson("/api/v1/merchants/{$this->merchant->id}/notifications")->assertNotFound();
    }
}
