<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\Company;
use App\Models\WhatsAppAccount;
use App\Services\Push\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Audit de sécurité n° 2, lot 2 : connexion, assistance, adresses appelées par le serveur, WhatsApp.
 */
class SecurityLot2Test extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    /** Point 16 : le même numéro écrit autrement compte dans la même limite, et la limite vaut toutes IP confondues */
    public function test_login_limit_uses_the_normalized_number_and_the_account(): void
    {
        foreach (['0700000001', '+2250700000001', '07 00 00 00 01', '+225 07 00 00 00 01', '2250700000001'] as $login) {
            $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'faux-mot-de-passe'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['login' => '07-00-00-00-01', 'password' => 'faux'])->assertStatus(429);

        // Autres adresses IP : 10 essais par minute au plus sur le même compte
        foreach (range(1, 5) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->postJson('/api/v1/auth/login', ['login' => '0700000002', 'password' => 'faux'])->assertStatus(422);
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.1.{$i}"])
                ->postJson('/api/v1/auth/login', ['login' => '+2250700000002', 'password' => 'faux'])->assertStatus(422);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.1'])
            ->postJson('/api/v1/auth/login', ['login' => '0700000002', 'password' => 'faux'])->assertStatus(429);
    }

    /** Point 12 : l'API publique suit le réglage « Modifications des e-commerçants » */
    public function test_a_cashier_cannot_do_everything_alone(): void
    {
        $cashier = $this->userWithRole(Role::Cashier);
        Sanctum::actingAs($cashier);
        $this->postJson("/api/v1/finance/merchants/{$this->merchant->id}/adjustments", ['amount' => 600000, 'description' => 'Geste'])->assertStatus(422);
        $this->postJson("/api/v1/finance/merchants/{$this->merchant->id}/adjustments", ['amount' => 9000000, 'description' => 'Geste'])->assertStatus(422);
        $this->postJson("/api/v1/finance/merchants/{$this->merchant->id}/adjustments", ['amount' => 5000, 'description' => 'Geste'])->assertCreated();

        $id = $this->postJson("/api/v1/merchants/{$this->merchant->id}/payment-methods", ['type' => 'wave', 'number' => '0709980885'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson("/api/v1/merchant-payment-methods/{$id}/verify")->assertStatus(422);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/finance/merchants/{$this->merchant->id}/adjustments", ['amount' => 600000, 'description' => 'Geste validé'])->assertCreated();
        $this->postJson("/api/v1/merchant-payment-methods/{$id}/verify")->assertOk()->assertJsonPath('data.status', 'verified');
    }

    /** Point 18 : l'adresse de l'ancienne application est contrôlée à chaque appel, sans redirection */
    public function test_push_endpoints_are_limited_to_known_services(): void
    {
        foreach (['https://fcm.googleapis.com/fcm/send/abc', 'https://updates.push.services.mozilla.com/wpush/v2/x', 'https://web.push.apple.com/Q', 'https://wns2-par02p.notify.windows.com/w/?token=x'] as $ok) {
            $this->assertTrue(WebPush::allowedEndpoint($ok), $ok);
        }
        foreach (['https://evil.example/push', 'https://fcm.googleapis.com.evil.example/x', 'http://fcm.googleapis.com/x', 'https://fcm.googleapis.com:8443/x', 'https://u@fcm.googleapis.com/x', 'https://127.0.0.1/x'] as $bad) {
            $this->assertFalse(WebPush::allowedEndpoint($bad), $bad);
        }
    }

    /** Point 20 : un identifiant WhatsApp recopié, jamais vérifié auprès de Meta, ne reçoit rien et ne bloque pas le titulaire */
    public function test_whatsapp_number_id_of_another_company_cannot_be_taken(): void
    {
        config(['messaging.whatsapp.driver' => 'auto']);
        $other = $this->userWithRole(Role::Admin, [], $otherCompany = Company::factory()->create());
        WhatsAppAccount::create(['company_id' => $this->company->id, 'owner_type' => 'company', 'phone_number_id' => '5551', 'status' => 'connected']);

        // L'autre entreprise ne peut pas reprendre un numéro vérifié
        Http::fake(['*' => Http::response(['error' => ['code' => 100, 'message' => 'Unsupported']], 400)]);
        Sanctum::actingAs($other);
        $this->putJson("http://{$otherCompany->slug}.".config('platform.domain').'/api/v1/whatsapp/settings', ['account' => ['phone_number_id' => '5551', 'access_token' => 'x']])
            ->assertJsonValidationErrors('account.phone_number_id');

        // Identifiant gardé sans vérification par une entreprise : il ne reçoit pas les messages, le vrai titulaire le reprend
        $squat = WhatsAppAccount::create(['company_id' => $otherCompany->id, 'owner_type' => 'company', 'phone_number_id' => '7777', 'status' => 'error']);
        $this->assertNull(WhatsAppAccount::withoutGlobalScopes()->where('phone_number_id', '7777')
            ->where('status', 'connected')->first());
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/whatsapp/settings', ['account' => ['phone_number_id' => '7777', 'access_token' => 'y']])->assertOk();
        $this->assertNull($squat->fresh()->phone_number_id);
        $this->assertSame('7777', WhatsAppAccount::forCompanyId($this->company->id)->phone_number_id);
    }
}
