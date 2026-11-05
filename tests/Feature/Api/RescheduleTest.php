<?php

namespace Tests\Feature\Api;

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\RescheduleRequest;
use App\Notifications\OrderAlert;
use App\Services\Orders\OrderDispatcher;
use App\Services\WhatsApp\Conversation;
use App\Services\WhatsApp\RescheduleConversation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Colis pas ramassés à leur date : cachés au livreur et hors assignation, file « À reporter », demande à
 * l'e-commerçant (dispatch ou message du soir), réponse par WhatsApp, page ou API publique, rappel du matin.
 */
class RescheduleTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const MERCHANT_PHONE = '+2250501020304';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'UTC'));
        $this->buildWorld();
        $this->company->forceFill(['timezone' => 'Africa/Abidjan'])->save();
        $this->merchant->update(['whatsapp_phone' => self::MERCHANT_PHONE]);
    }

    private function confirmed(string $day, array $data = []): Order
    {
        $order = $this->createOrder($data);
        $order->forceFill(['status' => OrderStatus::Confirmed, 'confirmed_at' => now(), 'delivery_scheduled_date' => $day])->save();

        return $order->fresh();
    }

    /** Messages WhatsApp envoyés depuis $lastId */
    private function outbound(int $lastId = 0)
    {
        return OutboundMessage::where('id', '>', $lastId)->orderBy('id')->get();
    }

    private function say(string $text, ?string $button = null): array
    {
        $lastId = (int) OutboundMessage::max('id');
        app(Conversation::class)->handle($this->company->fresh(), self::MERCHANT_PHONE, $button ? 'button' : 'text', $text, $button);
        $last = $this->outbound($lastId)->last();
        $this->assertNotNull($last, "Pas de réponse à « {$text} »");

        return ['text' => $last->body, 'buttons' => $last->payload['buttons'] ?? []];
    }

    public function test_a_late_pickup_is_hidden_from_the_courier_and_kept_out_of_assignment(): void
    {
        $late = $this->confirmed('2026-11-04');
        $today = $this->confirmed('2026-11-05');
        $dispatcher = app(OrderDispatcher::class);
        $dispatcher->assign($this->dispatcher, $today, AssignmentType::Pickup, $this->courierA);

        // Assigné hier (avant que la date ne passe), pas commencé
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'UTC'));
        $dispatcher->assign($this->dispatcher, $late, AssignmentType::Pickup, $this->courierA);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'UTC'));

        $this->assertTrue($late->fresh()->needsReschedule('2026-11-05'));
        Sanctum::actingAs($this->courierA->user);
        $codes = collect($this->getJson('/api/v1/courier/missions')->assertOk()->json('data'))->pluck('order.tracking_code');
        $this->assertContains($today->tracking_code, $codes);
        $this->assertNotContains($late->tracking_code, $codes);

        // Dispatch : file « À reporter », plus dans les ramassages en cours ; nouvelle assignation refusée
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/orders?queue=to_reschedule')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $late->id)
            ->assertJsonPath('data.0.to_reschedule', true);
        $this->getJson('/api/v1/orders/counts')->assertJsonPath('data.to_reschedule', 1)->assertJsonPath('data.to_pickup', 1);
        $this->postJson("/api/v1/orders/{$late->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierB->id])
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        $group = $this->getJson('/api/v1/orders/to-reschedule')->assertOk()->assertJsonPath('data.summary.orders', 1)
            ->assertJsonPath('data.summary.not_asked', 1)->json('data.groups.0');
        $this->assertSame('Boutique Test', $group['merchant']['business_name']);
        $this->assertSame('Koffi Ramasseur', $group['orders'][0]['courier_name']);
        $this->assertSame('2026-11-04', $group['missed_day']);

        // « Tout livrer aujourd'hui » : visible de nouveau chez le livreur, e-commerçant prévenu
        $this->postJson('/api/v1/orders/reschedule', ['order_ids' => [$late->id], 'date' => '2026-11-03'])->assertUnprocessable();
        $this->postJson('/api/v1/orders/reschedule', ['order_ids' => [$late->id], 'date' => '2026-11-05'])->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertSame('2026-11-05', $late->fresh()->delivery_scheduled_date->toDateString());
        $this->assertSame('colis_reportes', $this->outbound()->last()->template_name);
        Sanctum::actingAs($this->courierA->user);
        $this->assertContains($late->tracking_code, collect($this->getJson('/api/v1/courier/missions')->json('data'))->pluck('order.tracking_code'));
    }

    public function test_the_dispatch_asks_and_the_merchant_answers_on_the_page(): void
    {
        [$a, $b] = [$this->confirmed('2026-11-03'), $this->confirmed('2026-11-04')];
        Sanctum::actingAs($this->dispatcher);

        $this->postJson('/api/v1/reschedule-requests', ['merchant_ids' => [$this->merchant->id], 'note' => 'Désolé pour le retard'])
            ->assertCreated()->assertJsonPath('data.sent', 1);
        $request = RescheduleRequest::firstOrFail();
        $this->assertSame([$a->id, $b->id], $request->order_ids);
        $this->assertSame('2026-11-03', $request->missed_day->toDateString());
        $message = $this->outbound()->last();
        $this->assertSame('colis_a_reporter', $message->template_name);
        $this->assertStringContainsString('/reporter/'.$request->token, $message->body);
        $this->assertStringContainsString("1) {$a->tracking_code}", $message->body);

        // L'API publique et le webhook disent qu'une date est attendue
        $this->getJson("/api/v1/orders/{$a->id}")->assertJsonPath('data.reschedule.status', 'pending')
            ->assertJsonPath('data.reschedule.missed_day', '2026-11-03')->assertJsonPath('data.reschedule.max_date', '2026-11-19');
        $this->getJson('/api/v1/orders/to-reschedule')->assertJsonPath('data.summary.waiting', 1)->assertJsonPath('data.groups.0.request.id', $request->id);

        // Page de réponse (sans connexion)
        auth()->forgetGuards();
        $this->getJson('/api/v1/reschedule/'.$request->token)->assertOk()->assertJsonPath('data.open', true)
            ->assertJsonPath('data.merchant_name', 'Boutique Test')->assertJsonCount(2, 'data.orders')
            ->assertJsonPath('data.orders.0.recipient_name', 'Jean');
        $this->getJson('/api/v1/reschedule/inconnu')->assertNotFound();
        $this->postJson('/api/v1/reschedule/'.$request->token, ['choices' => [['order_id' => $a->id, 'date' => '2026-12-01']]])->assertUnprocessable();

        $this->postJson('/api/v1/reschedule/'.$request->token, ['choices' => [
            ['order_id' => $a->id, 'date' => '2026-11-06'],
            ['order_id' => $b->id, 'cancel' => 'out_of_stock'],
        ]])->assertOk()->assertJsonPath('data.open', false)->assertJsonPath('data.result.cancelled.0', $b->tracking_code);

        $this->assertSame('2026-11-06', $a->fresh()->delivery_scheduled_date->toDateString());
        $this->assertNull($a->fresh()->reschedule_request_id);
        $this->assertSame(OrderStatus::Cancelled, $b->fresh()->status);
        $this->assertStringContainsString('Produit en rupture', $b->fresh()->cancel_reason);
        $this->assertSame([RescheduleRequest::ANSWERED, 'page'], [$request->fresh()->status, $request->fresh()->answered_via]);
        $this->postJson('/api/v1/reschedule/'.$request->token, ['choices' => [['order_id' => $a->id, 'date' => '2026-11-07']]])->assertStatus(410);
    }

    public function test_the_evening_message_and_the_whatsapp_answer(): void
    {
        $this->company->forceFill(['reschedule_evening' => true, 'reschedule_evening_time' => '18:00', 'reschedule_channel' => 'whatsapp'])->save();
        [$a, $b, $c] = [$this->confirmed('2026-11-05'), $this->confirmed('2026-11-05'), $this->confirmed('2026-11-05')];
        $later = $this->confirmed('2026-11-06');

        $this->artisan('orders:reschedule')->assertSuccessful();
        $this->assertSame(0, RescheduleRequest::count());

        $this->travelTo(CarbonImmutable::parse('2026-11-05 18:00', 'UTC'));
        $this->artisan('orders:reschedule')->assertSuccessful();
        $this->artisan('orders:reschedule')->assertSuccessful();
        $request = RescheduleRequest::sole();
        $this->assertSame([$a->id, $b->id, $c->id], $request->order_ids);
        $this->assertNotContains($later->id, $request->order_ids);
        $this->assertStringContainsString('prévu(s) aujourd\'hui', $this->outbound()->last()->body);
        Notification::assertSentTo($this->dispatcher, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'reschedule_evening' && str_contains($n->title, '3 colis'));

        // Un message sans rapport passe au bot habituel
        $this->assertNull(app(RescheduleConversation::class)->handle($this->company, $this->merchant, self::MERCHANT_PHONE, 'Awa 0707070707 Yopougon 15000', null));

        $menu = $this->say('reporter');
        $this->assertStringContainsString("1) {$a->tracking_code}", $menu['text']);
        $this->assertArrayHasKey('rs:all_tomorrow', $menu['buttons']);

        $this->assertStringContainsString('pas dans la liste', $this->say('5 demain')['text']);
        // Colis 2 ramassé entre-temps : ignoré
        $b->forceFill(['status' => OrderStatus::PickedUp])->save();

        $reason = $this->say('1 2 demain et 3 annuler');
        $this->assertStringContainsString('Pourquoi annuler', $reason['text']);
        $recap = $this->say('4');
        $this->assertStringContainsString('ven. 6 nov.', $recap['text']);
        $this->assertStringContainsString('je livre moi-même', $recap['text']);
        $this->assertSame(['rs:confirm' => 'Oui', 'rs:fix' => 'Corriger'], $recap['buttons']);

        $done = $this->say('oui');
        $this->assertStringContainsString("{$a->tracking_code} le ven. 6 nov.", $done['text']);
        $this->assertStringContainsString("Annulé : {$c->tracking_code}", $done['text']);
        $this->assertStringContainsString("{$b->tracking_code} (déjà ramassé)", $done['text']);
        $this->assertSame('2026-11-06', $a->fresh()->delivery_scheduled_date->toDateString());
        $this->assertStringContainsString('Je livre moi-même', $c->fresh()->cancel_reason);
        $this->assertSame('2026-11-05', $b->fresh()->delivery_scheduled_date->toDateString());
        $this->assertSame(RescheduleRequest::ANSWERED, $request->fresh()->status);
        $this->assertSame('whatsapp', $request->fresh()->answered_via);

        // Le lendemain : plus de demande ouverte, le bot reprend la main
        $this->assertNull(app(RescheduleConversation::class)->handle($this->company, $this->merchant, self::MERCHANT_PHONE, '1 demain', null));
    }

    public function test_reminder_then_automatic_reschedule(): void
    {
        $this->company->forceFill(['reschedule_evening' => true, 'reschedule_evening_time' => '18:00'])->save();
        $order = $this->confirmed('2026-11-05');

        // Le soir : message WhatsApp (Jibiat Marchand n'affiche pas encore les demandes : contrat 1.10 pas repris)
        $this->travelTo(CarbonImmutable::parse('2026-11-05 18:00', 'UTC'));
        $this->artisan('orders:reschedule')->assertSuccessful();
        $request = RescheduleRequest::sole();
        $this->assertSame(1, OutboundMessage::where('template_name', 'colis_a_reporter')->count());

        // Le matin : rappel
        $this->travelTo(CarbonImmutable::parse('2026-11-06 08:00', 'UTC'));
        $this->artisan('orders:reschedule')->assertSuccessful();
        $this->artisan('orders:reschedule')->assertSuccessful();
        $this->assertSame(1, OutboundMessage::where('template_name', 'rappel_colis_a_reporter')->count());

        // Réponse de l'agence : nouvelle date
        Sanctum::actingAs($this->dispatcher);
        $this->postJson('/api/v1/orders/reschedule', ['order_ids' => [$order->id], 'date' => '2026-11-07'])->assertOk();
        $this->assertSame(RescheduleRequest::ANSWERED, $request->fresh()->status);

        // Réglage « report automatique » : sans réponse, la livraison passe au jour même et l'e-commerçant est prévenu
        $this->company->forceFill(['reschedule_no_answer' => 'auto_next_day'])->save();
        $other = $this->confirmed('2026-11-06');
        $this->travelTo(CarbonImmutable::parse('2026-11-06 18:00', 'UTC'));
        $this->artisan('orders:reschedule')->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-11-07 08:00', 'UTC'));
        $this->artisan('orders:reschedule')->assertSuccessful();
        $this->assertSame('2026-11-07', $other->fresh()->delivery_scheduled_date->toDateString());
        $this->assertNull($other->fresh()->reschedule_request_id);
        // Un message pour la date choisie par l'agence, un pour le report automatique
        $this->assertSame(2, OutboundMessage::where('template_name', 'colis_reportes')->count());
    }

    public function test_the_merchant_app_lists_and_reschedules_late_pickups(): void
    {
        $late = $this->confirmed('2026-11-04');
        $other = $this->confirmed('2026-11-05');
        Sanctum::actingAs($this->merchantUser);

        $this->getJson('/api/v1/orders?queue=to_reschedule')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $late->id);
        $this->getJson("/api/v1/orders/{$late->id}")->assertJsonPath('data.to_reschedule', true);
        $this->postJson("/api/v1/orders/{$other->id}/reschedule-date", ['date' => '2026-11-06'])->assertStatus(422);
        $this->postJson("/api/v1/orders/{$late->id}/reschedule-date", ['date' => '2026-12-01'])->assertUnprocessable();
        $this->postJson("/api/v1/orders/{$late->id}/reschedule-date", ['date' => '2026-11-05'])->assertOk();
        $this->assertSame('2026-11-05', $late->fresh()->delivery_scheduled_date->toDateString());
        $this->assertFalse($late->fresh()->needsReschedule('2026-11-05'));

        // Une course d'un autre e-commerçant : refusée
        $foreign = $this->confirmed('2026-11-04');
        $foreign->forceFill(['merchant_id' => Merchant::factory()->create(['company_id' => $this->company->id])->id])->save();
        $this->postJson("/api/v1/orders/{$foreign->id}/reschedule-date", ['date' => '2026-11-05'])->assertForbidden();
    }

    public function test_the_reply_parser(): void
    {
        $parser = app(RescheduleConversation::class);
        $parse = fn (string $text) => $parser->parse($this->company, RescheduleConversation::normalize($text), [1, 2, 3]);

        // Jeudi 5 novembre 2026
        $this->assertSame([1 => ['date' => '2026-11-06'], 2 => ['date' => '2026-11-09'], 3 => ['date' => '2026-11-06']], $parse('1 3 demain et 2 lundi'));
        $this->assertSame([1 => ['date' => '2026-11-09'], 2 => ['date' => '2026-11-09'], 3 => ['date' => '2026-11-09']], $parse('tout le 9/11'));
        $this->assertSame([1 => ['date' => '2026-11-07'], 2 => ['date' => '2026-11-07']], $parse('1, 2 après-demain'));
        $this->assertSame([2 => ['date' => '2026-11-12']], $parse('2 le 12 nov'));
        $this->assertSame([3 => ['date' => '2026-11-05']], $parse("3 aujourd'hui"));
        $this->assertSame([3 => ['cancel' => '?']], $parse('3 annuler'));
        $this->assertSame([3 => ['cancel' => 'out_of_stock']], $parse('3 annuler 2'));
        $this->assertSame([2 => ['cancel' => '?'], 3 => ['cancel' => '?']], $parse('annuler 2 et 3'));
        $this->assertSame([1 => ['date' => '2026-11-12']], $parse('1 jeudi'));
        $this->assertNull($parse('2 demain matin'));
        $this->assertNull($parse('Bonjour'));
        $this->assertNull($parse('1 3'));
        $this->assertNull($parse('ok merci'));
    }
}
