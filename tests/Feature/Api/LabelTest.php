<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Merchant;
use App\Services\Labels\LabelLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Étiquettes : réglages (entreprise, client), impression notée, lien signé de l'API publique.
 */
class LabelTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_settings_of_the_company_and_of_the_client(): void
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['label_settings' => ['client_print' => 'required', 'default_format' => 'a4', 'fields' => ['cod' => false]]])
            ->assertOk()->assertJsonPath('data.label_settings.client_print', 'required')->assertJsonPath('data.label_settings.fields.landmark', true);
        $this->getJson("/api/v1/orders/{$order->id}")->assertJsonPath('data.label.settings.default_format', 'a4')->assertJsonPath('data.label.settings.fields.cod', false);

        // Le client garde son propre réglage ; vide : celui de l'entreprise
        $this->patchJson("/api/v1/merchants/{$this->merchant->id}", ['label_settings' => ['client_print' => 'no']])->assertOk();
        $this->getJson("/api/v1/orders/{$order->id}")->assertJsonPath('data.label.settings.client_print', 'no')->assertJsonPath('data.label.settings.default_format', 'a4');
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.merchant.label_settings.client_print', 'no');

        // Les réglages de l'entreprise demandent les réglages généraux
        Sanctum::actingAs($this->dispatcher);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['label_settings' => ['client_print' => 'yes']])->assertForbidden();
    }

    public function test_printing_is_recorded(): void
    {
        $mine = $this->createOrder();
        $other = $this->createOrder([], $this->admin, Merchant::factory()->create(['company_id' => $this->company->id, 'pickup_zone_id' => $this->cocody->id]));
        Sanctum::actingAs($this->merchantUser);
        $this->postJson('/api/v1/orders/labels/printed', ['ids' => [$mine->id, $other->id]])->assertOk()->assertJsonPath('data.count', 1);
        $this->postJson('/api/v1/orders/labels/printed', ['ids' => [$mine->id]])->assertOk();
        $this->assertSame(2, $mine->fresh()->label_print_count);
        $this->assertNotNull($mine->fresh()->label_printed_at);
        $this->assertNull($other->fresh()->label_printed_at);

        // Pas encore imprimées : filtre de la liste
        Sanctum::actingAs($this->admin);
        $ids = collect($this->getJson('/api/v1/orders?label=unprinted')->json('data'))->pluck('id');
        $this->assertContains($other->id, $ids);
        $this->assertNotContains($mine->id, $ids);

        // Client qui n'imprime pas (réglage) : rien n'est noté
        $this->merchant->update(['label_settings' => ['client_print' => 'no']]);
        Sanctum::actingAs($this->merchantUser);
        $this->postJson('/api/v1/orders/labels/printed', ['ids' => [$mine->id]])->assertOk()->assertJsonPath('data.count', 0);
        Sanctum::actingAs($this->courierA->user);
        $this->postJson('/api/v1/orders/labels/printed', ['ids' => [$mine->id]])->assertForbidden();
    }

    public function test_public_api_gives_a_signed_label_link(): void
    {
        $order = $this->createOrder();
        [, $key] = ApiKey::issue($this->merchant, 'Jibiat Marchand', ['orders:read'], $this->merchantUser);
        $url = $this->getJson("/api/public/v1/orders/{$order->tracking_code}", ['Authorization' => 'Bearer '.$key])->assertOk()->json('data.label_url');
        $this->assertMatchesRegularExpression('#/etiquette-partagee/'.$order->id.'/\d+/[0-9a-f]{40}$#', $url);

        // Sans connexion : l'étiquette de cette course, avec l'entreprise et les réglages
        [$expires, $token] = array_slice(explode('/', $url), -2);
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/shared-labels/{$order->id}/{$expires}/{$token}")->assertOk()
            ->assertJsonPath('data.tracking_code', $order->tracking_code)
            ->assertJsonPath('data.company.name', $this->company->name)
            ->assertJsonPath('data.label_settings.client_print', 'yes');
        $this->postJson("/api/v1/shared-labels/{$order->id}/{$expires}/{$token}/printed")->assertOk();
        $this->assertSame(1, $order->fresh()->label_print_count);

        // Autre course, jeton modifié, lien expiré : refusé
        $this->getJson('/api/v1/shared-labels/'.($order->id + 1)."/{$expires}/{$token}")->assertForbidden();
        $this->getJson("/api/v1/shared-labels/{$order->id}/{$expires}/".str_repeat('0', 40))->assertForbidden();
        $this->travel(LabelLinks::DAYS + 1)->days();
        $this->getJson("/api/v1/shared-labels/{$order->id}/{$expires}/{$token}")->assertForbidden();
    }
}
