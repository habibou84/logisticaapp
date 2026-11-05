<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\Company;
use App\Models\MerchantPaymentMethod;
use App\Models\OutboundMessage;
use App\Notifications\MerchantPaymentMethodChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Moyens de paiement des marchands : ajoutés par le marchand (à vérifier par l'agence) ou par la caisse
 * (vérifiés d'office), alerte et message de sécurité, historique, et proposés à la caisse.
 */
class MerchantPaymentMethodTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Storage::fake('local');
    }

    private function url(): string
    {
        return "/api/v1/merchants/{$this->merchant->id}/payment-methods";
    }

    public function test_the_merchant_adds_a_method_that_stays_to_verify_and_alerts_the_cashier(): void
    {
        Notification::fake();
        $cashier = $this->userWithRole(Role::Cashier);
        Sanctum::actingAs($this->merchantUser);

        $method = $this->post($this->url(), [
            'type' => 'wave', 'number' => '07 09 98 08 85', 'holder_name' => 'Awa Koné',
            'payment_link' => 'https://pay.wave.com/m/M_ci_boutique', 'qr' => UploadedFile::fake()->image('qr.png'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.is_preferred', true)
            ->assertJsonPath('data.display_number', '07 09 98 08 85')
            ->json('data');
        $this->get('/api/v1'.$method['qr_url'])->assertOk();

        // La caisse est alertée, le marchand reçoit un message de sécurité
        Notification::assertSentTo([$this->admin, $cashier], MerchantPaymentMethodChanged::class);
        Notification::assertNotSentTo($this->dispatcher, MerchantPaymentMethodChanged::class);
        $this->assertTrue(OutboundMessage::where('template_name', 'moyen_paiement_modifie')->where('merchant_id', $this->merchant->id)->exists());

        // Un lien qui n'est pas une adresse web est refusé ; un numéro invalide aussi
        $this->postJson($this->url(), ['type' => 'orange_money', 'number' => '07 77 12 34 56', 'payment_link' => 'javascript:alert(1)'])->assertStatus(422);
        $this->postJson($this->url(), ['type' => 'orange_money', 'number' => '12'])->assertStatus(422);
    }

    public function test_the_cashier_verifies_and_a_merchant_change_needs_a_new_check(): void
    {
        Sanctum::actingAs($this->merchantUser);
        $id = $this->postJson($this->url(), ['type' => 'wave', 'number' => '0709980885'])->json('data.id');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/merchant-payment-methods/{$id}/verify")->assertOk()
            ->assertJsonPath('data.status', 'verified')->assertJsonPath('data.verified_by', $this->admin->name);

        // Le marchand change le numéro : à vérifier de nouveau
        Sanctum::actingAs($this->merchantUser);
        $this->postJson("/api/v1/merchant-payment-methods/{$id}", ['type' => 'wave', 'number' => '0101010101'])->assertOk()
            ->assertJsonPath('data.status', 'pending');
        // Changer seulement l'ordre ne demande pas de nouvelle vérification
        $other = $this->postJson($this->url(), ['type' => 'mtn_momo', 'number' => '0544221100'])->assertJsonPath('data.is_preferred', false)->json('data.id');
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/merchant-payment-methods/{$other}/verify")->assertOk();
        Sanctum::actingAs($this->merchantUser);
        $this->postJson("/api/v1/merchant-payment-methods/{$other}/preferred")->assertOk()
            ->assertJsonPath('data.is_preferred', true)->assertJsonPath('data.status', 'verified');
        $this->assertFalse(MerchantPaymentMethod::find($id)->is_preferred);

        // Le marchand ne valide pas lui-même et ne voit pas l'historique
        $this->postJson("/api/v1/merchant-payment-methods/{$id}/verify")->assertForbidden();
        $this->getJson($this->url())->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('history', null);

        // La caisse voit l'historique, refuse un moyen
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/merchant-payment-methods/{$id}/reject", ['reason' => 'Numéro inconnu du marchand'])->assertJsonPath('data.status', 'rejected');
        $history = $this->getJson($this->url())->assertOk()->json('history');
        $this->assertSame(['rejected', 'preferred', 'verified', 'added', 'updated', 'verified', 'added'], array_column($history, 'action'));
    }

    public function test_the_cashier_enters_a_verified_method_and_sees_it_at_payment_time(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->admin);
        $this->postJson($this->url(), ['type' => 'bank', 'number' => 'ci008 01234', 'bank_name' => 'SGCI'])->assertCreated()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.summary', 'Virement bancaire SGCI CI008 01234');
        Notification::assertNothingSent();

        $row = collect($this->getJson('/api/v1/finance/merchants?all=1')->assertOk()->json('data'))->firstWhere('merchant_id', $this->merchant->id);
        $this->assertSame(['summary' => 'Virement bancaire SGCI CI008 01234', 'status' => 'verified'], $row['payment_method']);
        $this->assertSame(0, $row['payment_methods_pending']);

        // Retirer le moyen préféré : le suivant prend sa place
        $wave = $this->postJson($this->url(), ['type' => 'wave', 'number' => '0709980885'])->json('data.id');
        $bank = MerchantPaymentMethod::where('type', 'bank')->first();
        $this->deleteJson("/api/v1/merchant-payment-methods/{$bank->id}")->assertNoContent();
        $this->assertTrue(MerchantPaymentMethod::find($wave)->is_preferred);
    }

    public function test_methods_stay_within_the_merchant_and_the_company(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson($this->url(), ['type' => 'wave', 'number' => '0709980885'])->json('data.id');

        // Un employé du marchand ou un dispatcher ne touche pas aux moyens de paiement
        Sanctum::actingAs($this->userWithRole(Role::MerchantStaff, ['merchant_id' => $this->merchant->id]));
        $this->getJson($this->url())->assertForbidden();
        Sanctum::actingAs($this->dispatcher);
        $this->getJson($this->url())->assertForbidden();

        // Une autre entreprise ne le voit pas
        Sanctum::actingAs($this->userWithRole(Role::Admin, [], Company::factory()->create()));
        $this->postJson("/api/v1/merchant-payment-methods/{$id}/verify")->assertNotFound();
    }
}
