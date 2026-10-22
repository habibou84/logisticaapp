<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\CourierEarning;
use App\Models\IncidentReason;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\OrderAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Colis perdu : déclaration par un administrateur, indemnité au marchand,
 * retenue sur la paie du livreur, alerte « peut-être perdu ».
 */
class LostParcelTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->merchant->update(['whatsapp_phone' => '0501020304']);
    }

    private function failedWithB(): Order
    {
        $order = $this->createOrder(['items_amount' => 12000]);
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $reason = IncidentReason::where('applies_to', '!=', 'pickup')->where('requires_date', false)->where('triggers_return', false)->first();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivery_failed', 'incident_reason_id' => $reason->id])->assertOk();

        return $order->fresh();
    }

    public function test_admin_declares_a_lost_parcel(): void
    {
        $order = $this->failedWithB();
        $payload = ['reason' => 'Introuvable après le point de caisse', 'compensation' => 12000, 'courier_deduction' => 5000];

        // Le dispatcher seul ne peut pas engager la caisse ; le statut générique est refusé
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/lost", $payload)->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'lost'])->assertJsonValidationErrors('status');

        $this->postJson("/api/v1/orders/{$order->id}/lost", $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'lost')
            ->assertJsonPath('data.status_label', 'Perdu');

        $order->refresh();
        $this->assertNull($order->held_by_courier_id);
        $this->assertNotNull($order->lost_at);
        $this->assertSame('Introuvable après le point de caisse', $order->lost_reason);

        $this->assertSame(12000, (int) MerchantLedgerEntry::where('order_id', $order->id)->where('type', 'lost_compensation')->sum('amount'));
        $deduction = CourierEarning::where('order_id', $order->id)->where('type', 'lost_parcel')->sole();
        $this->assertSame($this->courierB->id, $deduction->courier_id, 'Par défaut, le livreur qui avait le colis');
        $this->assertSame(-5000, $deduction->amount);

        $message = OutboundMessage::where('template_name', 'incident_livraison')->latest('id')->first();
        $this->assertStringContainsString('colis perdu', $message->body);
        $this->assertStringContainsString('12 000', $message->body);

        // Statut final : plus rien à faire
        $this->postJson("/api/v1/orders/{$order->id}/lost", $payload)->assertJsonValidationErrors('status');
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/decision", ['decision' => 'return'])->assertJsonValidationErrors('decision');
    }

    public function test_without_deduction_and_before_pickup(): void
    {
        Sanctum::actingAs($this->admin);
        $fresh = $this->createOrder();
        $this->postJson("/api/v1/orders/{$fresh->id}/lost", ['reason' => 'x', 'compensation' => 0])->assertJsonValidationErrors('status');

        $order = $this->failedWithB();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/orders/{$order->id}/lost", ['reason' => 'Volé au dépôt', 'compensation' => 8000, 'courier_deduction' => 0])->assertOk();
        $this->assertSame(0, CourierEarning::where('type', 'lost_parcel')->count());
    }

    public function test_admins_are_told_a_parcel_may_be_lost(): void
    {
        Notification::fake();
        $order = $this->failedWithB();
        $admin = User::find($this->admin->id);

        $this->travel(25)->hours();
        $this->artisan('parcels:overdue');
        $this->assertSame(0, $this->maybeLost($admin));

        $this->travel(48)->hours();
        $this->artisan('parcels:overdue');
        $this->artisan('parcels:overdue');
        $this->assertSame(1, $this->maybeLost($admin));
        $this->assertSame(0, $this->maybeLost($this->dispatcher), 'Seuls les administrateurs');
        $this->assertNotNull($order->fresh()->hold_escalated_at);
        $this->assertTrue($admin->hasRole(Role::Admin->value));
    }

    private function maybeLost(User $user): int
    {
        return Notification::sent($user, OrderAlert::class)->filter(fn (OrderAlert $n) => $n->kind === 'parcel_maybe_lost')->count();
    }
}
