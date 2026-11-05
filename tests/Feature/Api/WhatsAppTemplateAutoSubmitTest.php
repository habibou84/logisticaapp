<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Enums\WhatsAppTemplate;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Modèles WhatsApp soumis à Meta tout seuls : à l'ouverture de la caisse (reçu de versement, rappel au livreur) et
 * chaque nuit (whatsapp:templates). Un modèle refusé n'est pas renvoyé en boucle.
 */
class WhatsAppTemplateAutoSubmitTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private WhatsAppAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        config(['messaging.whatsapp.driver' => 'auto']);
        $this->buildWorld();
        $this->account = WhatsAppAccount::create([
            'company_id' => $this->company->id, 'owner_type' => 'company', 'provider' => 'meta_cloud',
            'phone_number_id' => '1234', 'waba_id' => '987', 'access_token' => 'tok', 'status' => 'connected',
        ]);
        // Déjà connu de Meta avant cette version : tous les modèles sauf ceux de la caisse
        foreach (WhatsAppTemplate::cases() as $template) {
            if (! in_array($template, [WhatsAppTemplate::CashReceipt, WhatsAppTemplate::CashReminder, WhatsAppTemplate::CashClosing], true)) {
                $this->account->templates()->create(['name' => $template->value, 'language' => WhatsAppTemplate::LANGUAGE, 'status' => 'approved', 'category' => 'utility']);
            }
        }
    }

    /** @return list<string> */
    private function submitted(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/987/message_templates'))
            ->map(fn (Request $r) => $r['name'])->values()->all();
    }

    public function test_opening_the_cash_desk_submits_the_missing_templates_once(): void
    {
        Http::fake([
            // État lu chez Meta : rien la première fois, puis les deux modèles acceptés
            '*/987/message_templates?*' => Http::sequence()->push(['data' => []])->push(['data' => [
                ['name' => 'versement_recu', 'language' => 'fr', 'status' => 'APPROVED', 'category' => 'UTILITY'],
                ['name' => 'argent_a_remettre', 'language' => 'fr', 'status' => 'APPROVED', 'category' => 'UTILITY'],
                ['name' => 'cloture_caisse', 'language' => 'fr', 'status' => 'APPROVED', 'category' => 'UTILITY'],
            ]]),
            '*/987/message_templates' => Http::response(['id' => 't1', 'status' => 'PENDING']),
        ]);
        Sanctum::actingAs($this->userWithRole(Role::Cashier));

        $this->getJson('/api/v1/finance/cash')->assertOk()->assertJsonPath('whatsapp_templates', 'pending');
        $this->assertSame(['versement_recu', 'argent_a_remettre', 'cloture_caisse'], $this->submitted());

        // Rouverte aussitôt : rien n'est renvoyé
        $this->getJson('/api/v1/finance/cash')->assertJsonPath('whatsapp_templates', 'pending');
        $this->assertCount(3, $this->submitted());

        // Acceptés par Meta : lus à la visite suivante, plus de message
        Cache::flush();
        $this->getJson('/api/v1/finance/cash');
        $this->getJson('/api/v1/finance/cash')->assertJsonPath('whatsapp_templates', 'approved');
    }

    public function test_rejected_templates_are_not_resubmitted(): void
    {
        Http::fake([
            '*/987/message_templates?*' => Http::response(['data' => [
                ['name' => 'versement_recu', 'language' => 'fr', 'status' => 'REJECTED', 'category' => 'UTILITY', 'rejected_reason' => 'INVALID_FORMAT'],
            ]]),
            '*/987/message_templates' => Http::response(['id' => 't1', 'status' => 'PENDING']),
        ]);

        $this->artisan('whatsapp:templates')->assertSuccessful();
        $this->assertSame(['argent_a_remettre', 'cloture_caisse'], $this->submitted());

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/finance/cash')->assertJsonPath('whatsapp_templates', 'rejected');
        $this->artisan('whatsapp:templates')->assertSuccessful();
        $this->assertCount(2, $this->submitted());
    }
}
