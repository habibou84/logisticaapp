<?php

namespace Tests\Feature\Api;

use App\Enums\WhatsAppTemplate;
use App\Models\OutboundMessage;
use App\Services\Messaging\AutomaticMessages;
use App\Services\Messaging\Messenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/** WhatsApp › Messages automatiques : une ligne par message, l'interrupteur écrit le réglage qui décide de l'envoi. */
class AutomaticMessagesTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Queue::fake();
        Sanctum::actingAs($this->admin);
    }

    private function row(string $id): array
    {
        return collect($this->getJson('/api/v1/whatsapp/messages')->assertOk()->json('data.messages'))->firstWhere('id', $id);
    }

    public function test_every_template_has_its_line_with_an_example_text(): void
    {
        $messages = $this->getJson('/api/v1/whatsapp/messages')->assertOk()->json('data.messages');

        $listed = collect($messages)->pluck('templates')->flatten()->unique()->sort()->values()->all();
        $this->assertSame(collect(WhatsAppTemplate::cases())->pluck('value')->sort()->values()->all(), $listed);
        foreach ($messages as $m) {
            $this->assertNotSame('', $m['text'], $m['id']);
            $this->assertStringNotContainsString('{{', $m['text'], $m['id']);
        }
    }

    public function test_switches_write_the_setting_that_decides(): void
    {
        // Étape des clients
        $this->putJson('/api/v1/whatsapp/messages/picked_up', ['on' => true])->assertOk()->assertJsonPath('data.on', true);
        $this->assertTrue($this->company->fresh()->merchant_whatsapp_events['order.picked_up']);

        // Réglage de l'entreprise
        $this->putJson('/api/v1/whatsapp/messages/out_for_delivery', ['on' => false])->assertOk()->assertJsonPath('data.on', false);
        $this->assertFalse($this->company->fresh()->notify_recipients);

        // Interrupteur du modèle : le message ne part plus
        $messenger = app(Messenger::class);
        $send = fn () => $messenger->whatsapp($this->company->id, '0707070707', 'merchant', WhatsAppTemplate::RescheduleDone, ['Boutique', 2, 'LV-1 le 3 nov.']);
        $this->assertNotNull($send());
        $this->putJson('/api/v1/whatsapp/messages/reschedule_done', ['on' => false])->assertOk();
        $this->assertFalse($this->row('reschedule_done')['on']);
        $this->assertNull($send());
        $this->assertSame(1, OutboundMessage::count());

        // Ligne sans interrupteur, message inconnu
        $this->putJson('/api/v1/whatsapp/messages/payment_method', ['on' => false])->assertNotFound();
        $this->putJson('/api/v1/whatsapp/messages/inconnu', ['on' => false])->assertNotFound();
    }

    public function test_only_messaging_managers_reach_it(): void
    {
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/whatsapp/messages')->assertForbidden();
        $this->assertNotEmpty(app(AutomaticMessages::class)->catalog());
    }
}
