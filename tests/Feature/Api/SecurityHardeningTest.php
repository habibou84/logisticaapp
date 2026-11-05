<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\OrderAssignment;
use App\Services\Accounts\PhoneVerifier;
use App\Services\Webhooks\UrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Corrections de l'audit de sécurité : code de démonstration, webhooks vers le réseau interne, droits des livreurs
 * et des administrateurs, code de livraison, en-têtes.
 */
class SecurityHardeningTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_the_sms_code_is_never_shown_in_production(): void
    {
        config(['messaging.whatsapp.driver' => 'auto', 'messaging.sms.driver' => 'log']);
        $this->assertTrue(app(PhoneVerifier::class)->demoMode());

        $this->app['env'] = 'production';
        $this->assertFalse(app(PhoneVerifier::class)->demoMode());
    }

    public function test_webhooks_cannot_target_hidden_internal_addresses(): void
    {
        foreach (['::ffff:127.0.0.1', '64:ff9b::7f00:1', '2002:7f00:1::1', '100.64.0.1', '192.0.0.1', '198.18.0.1', '10.1.2.3', '::1', 'fe80::1'] as $ip) {
            $this->assertFalse(UrlGuard::isPublicIp($ip), $ip);
        }
        $this->assertTrue(UrlGuard::isPublicIp('8.8.8.8'));
        $this->assertTrue(UrlGuard::isPublicIp('2606:4700::1111'));

        config(['services.webhooks.allow_private_targets' => false]);
        $this->assertNotNull(UrlGuard::blockedReason('https://[::ffff:127.0.0.1]:8443/'));
    }

    public function test_a_courier_cannot_add_fees_to_a_course_taken_from_him(): void
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
        OrderAssignment::query()->where('order_id', $order->id)->update(['status' => 'cancelled']);

        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/expenses", ['type' => 'transport', 'amount' => 50000])->assertForbidden();
        $this->getJson("/api/v1/orders/{$order->id}")->assertForbidden();
    }

    public function test_an_admin_cannot_take_over_another_admin(): void
    {
        $other = $this->userWithRole(Role::Admin);
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/v1/users/{$other->id}", ['password' => 'nouveau-mot-de-passe'])->assertForbidden();
        $this->patchJson("/api/v1/users/{$other->id}", ['phone' => '0709090909'])->assertForbidden();
        $this->postJson("/api/v1/users/{$other->id}/invitation")->assertForbidden();
        // Son nom reste modifiable, et ses propres identifiants aussi
        $this->patchJson("/api/v1/users/{$other->id}", ['name' => 'Co-gérant'])->assertOk();
        $this->patchJson("/api/v1/users/{$this->dispatcher->id}", ['password' => 'nouveau-mot-de-passe'])->assertOk();
    }

    public function test_the_delivery_code_is_blocked_after_five_wrong_tries(): void
    {
        $this->company->update(['require_delivery_code' => true]);
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierA->id])->assertOk();
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $wrong = $order->fresh()->delivery_code === '0000' ? '1111' : '0000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered', 'delivery_code' => $wrong])
                ->assertJsonValidationErrors(['delivery_code' => 'Code de livraison incorrect.']);
        }
        // Même le bon code attend : le code ne se devine pas en essayant
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered', 'delivery_code' => $order->fresh()->delivery_code])
            ->assertJsonValidationErrors('delivery_code');
    }

    public function test_security_headers_are_sent(): void
    {
        $this->getJson('/api/v1/branding')->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
