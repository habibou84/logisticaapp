<?php

namespace Tests\Feature\Api;

use App\Enums\WhatsAppTemplate;
use App\Models\Company;
use App\Models\OutboundMessage;
use App\Models\WhatsAppAccount;
use App\Services\Accounts\PhoneVerifier;
use App\Services\Messaging\Messenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * WhatsApp réel entreprise par entreprise (WHATSAPP_DRIVER=auto) : connexion du numéro
 * vérifiée auprès de Meta, abonnement aux messages reçus, soumission des modèles.
 */
class WhatsAppConnectionTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['messaging.whatsapp.driver' => 'auto']);
        $this->buildWorld();
    }

    private function account(array $attributes = [], ?Company $company = null): WhatsAppAccount
    {
        return WhatsAppAccount::create([
            'company_id' => ($company ?? $this->company)->id, 'owner_type' => 'company',
            'phone_number_id' => '1234', 'waba_id' => '987', 'access_token' => 'tok', 'status' => 'pending',
            ...$attributes,
        ]);
    }

    public function test_connected_company_sends_for_real_while_others_stay_in_simulation(): void
    {
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.REAL']]])]);

        // Numéro renseigné mais pas encore vérifié : simulation, aucun appel à Meta
        $this->account();
        $simulated = app(Messenger::class)->test($this->company->id, '0700000099')->fresh();
        $this->assertNotSame('wamid.REAL', $simulated->provider_message_id);
        Http::assertNothingSent();

        // Connecté : envoi réel
        WhatsAppAccount::query()->update(['status' => 'connected']);
        $real = app(Messenger::class)->test($this->company->id, '0700000099')->fresh();
        $this->assertSame('wamid.REAL', $real->provider_message_id);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/1234/messages') && $r->hasHeader('Authorization', 'Bearer tok'));

        // WHATSAPP_DRIVER=log : simulation imposée partout
        config(['messaging.whatsapp.driver' => 'log']);
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/whatsapp/settings')->assertJsonPath('data.mode', 'forced_simulation');
        config(['messaging.whatsapp.driver' => 'auto']);
        $this->getJson('/api/v1/whatsapp/settings')->assertJsonPath('data.mode', 'live');
    }

    public function test_connection_check_explains_meta_errors(): void
    {
        // Jeton refusé, puis accepté une fois corrigé chez Meta
        Http::fake([
            '*/1234?*' => Http::sequence()
                ->push(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']], 401)
                ->push(['display_phone_number' => '+225 07 00 00 00 99', 'verified_name' => 'Rapide Express', 'quality_rating' => 'GREEN']),
            '*/987/subscribed_apps' => Http::response(['success' => true]),
        ]);
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/whatsapp/settings', ['account' => ['phone_number_id' => '1234', 'waba_id' => '987', 'access_token' => 'expired']])->assertOk()
            ->assertJsonPath('data.mode', 'simulation')
            ->assertJsonPath('data.account.status', 'error')
            ->assertJsonPath('data.account.last_error', 'Jeton d\'accès invalide ou expiré. Créez un jeton permanent (utilisateur système) dans Meta Business.');

        // Nouvelle vérification une fois le jeton corrigé chez Meta
        $data = $this->postJson('/api/v1/whatsapp/connect')->assertOk()->json('data');
        $this->assertSame(['live', 'connected', null, '+2250700000099'], [$data['mode'], $data['account']['status'], $data['account']['last_error'], $data['account']['display_phone']]);
        $this->assertNotNull($data['account']['webhook_subscribed_at']);
    }

    public function test_templates_are_submitted_to_meta(): void
    {
        $account = $this->account(['status' => 'connected']);
        // Déjà approuvé : pas soumis de nouveau
        $account->templates()->create(['name' => WhatsAppTemplate::OrderDelivered->value, 'language' => 'fr', 'status' => 'approved']);

        Http::fake(function (HttpRequest $request) {
            if (! str_ends_with($request->url(), '/987/message_templates')) {
                return Http::response([], 404);
            }

            // Déjà créé chez Meta : accepté sans erreur
            return $request['name'] === WhatsAppTemplate::PayoutPaid->value
                ? Http::response(['error' => ['code' => 100, 'error_subcode' => 2388024, 'message' => 'exists']], 400)
                : Http::response(['id' => 'tpl', 'status' => 'PENDING']);
        });

        Sanctum::actingAs($this->admin);
        $response = $this->postJson('/api/v1/whatsapp/templates/submit')->assertOk();

        $expected = count(WhatsAppTemplate::cases()) - 1;
        $this->assertSame($expected, $response->json('meta.submitted'));
        $this->assertSame([], $response->json('meta.errors'));
        Http::assertSentCount($expected);
        Http::assertSent(fn (HttpRequest $r) => $r['name'] === 'colis_en_route' && $r['category'] === 'UTILITY'
            && $r['components'][0]['example']['body_text'][0] === WhatsAppTemplate::OutForDelivery->example());

        $statuses = collect($response->json('data.templates'))->pluck('status', 'name');
        $this->assertSame('pending', $statuses['colis_en_route']);
        $this->assertSame('approved', $statuses['colis_livre']);
    }

    public function test_verification_uses_whatsapp_only_once_the_number_is_connected(): void
    {
        $account = $this->account(['display_phone' => '0700000099']);
        $verifier = app(PhoneVerifier::class);
        $this->assertNull($verifier->whatsappNumber($this->company));

        $account->update(['status' => 'connected']);
        $this->assertSame('+2250700000099', $verifier->whatsappNumber($this->company));
        $this->assertSame(0, OutboundMessage::count());
    }
}
