<?php

namespace Tests\Feature;

use App\Models\InboundMessage;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppSession;
use App\Models\Zone;
use App\Services\WhatsApp\ClaudeOrderParser;
use App\Services\WhatsApp\Conversation;
use App\Services\WhatsApp\HeuristicOrderParser;
use App\Services\WhatsApp\OrderMessageParser;
use App\Services\WhatsApp\ZoneMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

class WhatsAppConversationTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const MERCHANT_PHONE = '+2250501020304';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->merchant->update(['whatsapp_phone' => self::MERCHANT_PHONE]);
    }

    /**
     * Envoie un message au bot et renvoie ses réponses (texte et boutons).
     *
     * @return list<array{text: string, buttons: array<string, string>}>
     */
    private function say(?string $text, ?string $button = null, string $from = self::MERCHANT_PHONE): array
    {
        $lastId = (int) OutboundMessage::max('id');
        app(Conversation::class)->handle($this->company->fresh(), $from, $button ? 'button' : 'text', $text, $button);

        return OutboundMessage::where('id', '>', $lastId)->orderBy('id')->get()
            ->map(fn ($m) => ['text' => $m->body, 'buttons' => $m->payload['buttons'] ?? []])->all();
    }

    private function lastReply(?string $text, ?string $button = null): array
    {
        $replies = $this->say($text, $button);
        $this->assertNotEmpty($replies);

        return end($replies);
    }

    public function test_unknown_numbers_are_turned_away(): void
    {
        $reply = $this->say('Bonjour', null, '+2250799999999')[0];

        $this->assertStringContainsString("n'est pas enregistré", $reply['text']);
        $this->assertSame(0, WhatsAppSession::count());
        $this->assertSame(1, InboundMessage::whereNull('merchant_id')->count());
    }

    public function test_greeting_shows_the_menu(): void
    {
        $reply = $this->lastReply('Bonjour');

        $this->assertStringContainsString('Boutique Test', $reply['text']);
        $this->assertSame(['new', 'point', 'track'], array_keys($reply['buttons']));
        $this->assertSame('conversation', OutboundMessage::latest('id')->first()->event);
    }

    public function test_structured_message_becomes_an_order_after_confirmation(): void
    {
        $this->lastReply(null, 'new');

        $recap = $this->lastReply("Nom : Awa Koné\nTél : 07 08 09 10 11\nCommune : Yopougon\nAdresse : Siporex\nRepère : face pharmacie\nArticle : 2 pagnes\nMontant : 15 000");

        $this->assertStringContainsString('Récapitulatif', $recap['text']);
        $this->assertStringContainsString('Awa Koné · 07 08 09 10 11', $recap['text']);
        $this->assertStringContainsString('Yopougon — Siporex', $recap['text']);
        $this->assertStringContainsString('Livraison : 1 500 F', $recap['text']);
        $this->assertSame(['confirm', 'edit', 'cancel'], array_keys($recap['buttons']));

        $done = $this->lastReply('✅ Confirmer', 'confirm');

        $order = Order::sole();
        $this->assertSame('whatsapp', $order->source);
        $this->assertSame(['Awa Koné', '+2250708091011', 15000, 'Siporex', 'face pharmacie', '2 pagnes'],
            [$order->recipient_name, $order->recipient_phone, $order->items_amount, $order->delivery_address, $order->delivery_landmark, $order->description]);
        $this->assertSame($this->yopougon->id, $order->delivery_zone_id);
        $this->assertStringContainsString($order->tracking_code, $done['text']);
        $this->assertSame($order->id, InboundMessage::latest('id')->first()->order_id);
        $this->assertSame('idle', WhatsAppSession::sole()->state);
    }

    public function test_missing_information_is_asked_one_question_at_a_time(): void
    {
        $this->assertStringContainsString('numéro du client', $this->lastReply('Une livraison à Yopougon svp')['text']);
        $this->assertArrayNotHasKey('delivery_address', WhatsAppSession::sole()->draft);
        $this->assertStringContainsString('Combien', $this->lastReply('07 08 09 10 11')['text']);

        $recap = $this->lastReply('déjà payé');
        $this->assertStringContainsString('Articles : 0 F', $recap['text']);

        $this->lastReply('oui');
        $this->assertSame(0, Order::sole()->items_amount);
    }

    public function test_forwarded_customer_message_is_understood(): void
    {
        $recap = $this->lastReply("Bonsoir, c'est pour Awa, elle est à Yopougon Siporex face à la pharmacie du marché. "
            .'Son numéro 07 08 09 10 11, total 12 500 F, livraison à la charge du client');

        $this->assertStringContainsString('Awa · 07 08 09 10 11', $recap['text']);
        $this->assertStringContainsString('Yopougon — Siporex', $recap['text']);
        $this->assertStringContainsString('Articles : 12 500 F', $recap['text']);
        $this->assertStringContainsString('payée par le client', $recap['text']);
        $this->assertStringContainsString('Le client paiera : 14 000 F', $recap['text']);
        $this->assertStringContainsString('Repère : face à la pharmacie du marché', $recap['text']);
    }

    public function test_ambiguous_zone_is_chosen_with_buttons_and_corrections_update_the_recap(): void
    {
        $r2 = $this->zone('Riviera 2', $this->cocody);
        $this->zone('Riviera 3', $this->cocody);
        $this->rule($this->grid, $this->cocody, $r2, 1200);

        $choice = $this->lastReply("Tél : 0708091011\nCommune : riviera\nMontant : 8000");
        $this->assertSame(['zone:'.$r2->id, 'zone:'.($r2->id + 1)], array_keys($choice['buttons']));

        $recap = $this->lastReply('Riviera 2', 'zone:'.$r2->id);
        $this->assertStringContainsString('Cocody › Riviera 2', $recap['text']);
        $this->assertStringContainsString('Livraison : 1 200 F', $recap['text']);

        $corrected = $this->lastReply('montant 9500');
        $this->assertStringContainsString('Articles : 9 500 F', $corrected['text']);

        $this->assertStringContainsString('annulée', $this->lastReply('annuler')['text']);
        $this->assertSame(0, Order::count());
    }

    public function test_tracking_and_daily_point(): void
    {
        $order = $this->createOrder(['items_amount' => 5000]);

        $tracking = $this->lastReply("Où en est {$order->tracking_code} ?");
        $this->assertStringContainsString($order->tracking_code, $tracking['text']);
        $this->assertStringContainsString('À encaisser : 5 000 F', $tracking['text']);

        $point = $this->lastReply(null, 'point');
        $this->assertStringContainsString('Point du jour', $point['text']);
        $this->assertStringContainsString('1 courses', $point['text']);
    }

    public function test_conversation_expires_and_can_be_disabled(): void
    {
        $this->lastReply('Une livraison à Yopougon');
        $this->assertSame('draft', WhatsAppSession::sole()->state);

        $this->travel(31)->minutes();
        $this->assertStringContainsString('Que voulez-vous faire', $this->lastReply('menu')['text']);
        $this->assertNull(WhatsAppSession::sole()->draft);

        $this->company->update(['whatsapp_orders' => false]);
        $this->assertStringContainsString("n'est pas activée", $this->lastReply('Bonjour')['text']);
    }

    public function test_meta_webhook_delivers_messages_once(): void
    {
        WhatsAppAccount::create(['company_id' => $this->company->id, 'owner_type' => 'company', 'phone_number_id' => '1098765']);

        $payload = ['entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => '1098765'],
            'messages' => [['id' => 'wamid.IN1', 'from' => '2250501020304', 'type' => 'text', 'text' => ['body' => 'Bonjour']]],
        ]]]]]];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();
        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        $this->assertSame(1, InboundMessage::count());
        $this->assertSame($this->merchant->id, InboundMessage::sole()->merchant_id);
        $this->assertSame(1, OutboundMessage::where('event', 'conversation')->count());

        // Réponse à un bouton
        $payload['entry'][0]['changes'][0]['value']['messages'][0] = [
            'id' => 'wamid.IN2', 'from' => '2250501020304', 'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'new', 'title' => '📦 Nouvelle course']],
        ];
        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();
        $this->assertSame('draft', WhatsAppSession::sole()->state);
    }

    public function test_simulator_is_for_admins(): void
    {
        Sanctum::actingAs($this->merchantUser);
        $this->postJson('/api/v1/whatsapp/simulate', ['from' => self::MERCHANT_PHONE, 'text' => 'Bonjour'])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/whatsapp/simulate', ['from' => self::MERCHANT_PHONE, 'text' => 'Bonjour'])
            ->assertOk()
            ->assertJsonPath('data.merchant_id', $this->merchant->id)
            ->assertJsonPath('data.replies.0.buttons.0.id', 'new');
    }

    public function test_claude_answers_are_validated_and_completed_by_the_rules(): void
    {
        $parser = new class(app(HeuristicOrderParser::class), app(ZoneMatcher::class)) extends ClaudeOrderParser
        {
            public ?array $answer = null;

            protected function extract(string $text, Collection $zones): ?array
            {
                return $this->answer;
            }
        };

        $zones = Zone::forCompany($this->company->id)->with('parent')->get();
        $parser->answer = [
            'recipient_name' => 'Mme Bamba', 'recipient_phone' => 'pas un numéro', 'recipient_phone2' => null,
            'zone_name' => 'Cocody › Angré', 'delivery_address' => '8e tranche', 'delivery_landmark' => null,
            'items_amount' => 22000, 'fee_payer' => 'recipient', 'description' => 'robe', 'merchant_note' => null,
        ];

        $result = $parser->parse('Mme Bamba 07 08 09 10 11 angré 8e tranche robe 22k livraison client', $zones, self::MERCHANT_PHONE);

        $this->assertSame('Mme Bamba', $result['recipient_name']);
        $this->assertSame('+2250708091011', $result['recipient_phone']); // numéro invalide de Claude : celui des règles
        $this->assertSame([$this->angre->id], $result['zones']);
        $this->assertSame(22000, $result['items_amount']);
        $this->assertSame('recipient', $result['fee_payer']);

        // Refus ou indisponibilité : analyse par règles seule
        $parser->answer = null;
        $this->assertSame([$this->angre->id], $parser->parse('07 08 09 10 11 Angré 5000 F', $zones)['zones']);
        $this->assertInstanceOf(HeuristicOrderParser::class, app(OrderMessageParser::class));
    }
}
