<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/** Annulation : motif obligatoire (liste du client ou de l'équipe), et la course garde qui a annulé et pourquoi. */
class CancellationReasonTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function cancel(User $user, Order $order, array $extra = [], string $status = 'cancelled')
    {
        Sanctum::actingAs($user);

        return $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => $status, ...$extra]);
    }

    public function test_team_must_give_a_reason_and_the_order_keeps_who_cancelled(): void
    {
        $order = $this->createOrder();

        $this->cancel($this->admin, $order)->assertJsonValidationErrors('cancel_reason_code');
        $this->cancel($this->admin, $order, ['cancel_reason_code' => 'self_delivery'])->assertJsonValidationErrors('cancel_reason_code');
        $this->cancel($this->admin, $order, ['cancel_reason_code' => 'other'])->assertJsonValidationErrors('cancel_reason');
        $this->assertNotSame('cancelled', $order->fresh()->status->value);

        $this->cancel($this->admin, $order, ['cancel_reason_code' => 'duplicate', 'cancel_reason' => 'Même colis que hier'])
            ->assertOk()
            ->assertJsonPath('data.cancellation.by', 'company')
            ->assertJsonPath('data.cancellation.name', strtok($this->admin->name, ' '))
            ->assertJsonPath('data.cancellation.reason', 'duplicate')
            ->assertJsonPath('data.cancellation.reason_label', 'Course en double')
            ->assertJsonPath('data.cancellation.note', 'Même colis que hier')
            ->assertJsonPath('data.cancellation.rejected', false);

        $order->refresh();
        $this->assertSame(['company', $this->admin->id], [$order->cancelled_by_type, $order->cancelled_by_id]);
        // Journal de la course : « Motif · précision »
        $this->assertSame('Course en double · Même colis que hier', $order->events()->latest('id')->first()->note);
    }

    public function test_client_chooses_from_its_own_list(): void
    {
        $order = $this->createOrder();

        $this->cancel($this->merchantUser, $order)->assertJsonValidationErrors('cancel_reason_code');
        $this->cancel($this->merchantUser, $order, ['cancel_reason_code' => 'duplicate'])->assertJsonValidationErrors('cancel_reason_code');
        $this->cancel($this->merchantUser, $order, ['cancel_reason_code' => 'out_of_stock'])
            ->assertOk()
            ->assertJsonPath('data.cancellation.by', 'merchant')
            ->assertJsonPath('data.cancellation.reason_label', 'Produit en rupture')
            ->assertJsonPath('data.cancellation.role', null);

        // Chaque côté reçoit sa liste
        $this->assertContains('out_of_stock', array_column($this->getJson('/api/v1/cancel-reasons')->assertOk()->json('data.reasons'), 'code'));
        Sanctum::actingAs($this->admin);
        $this->assertContains('duplicate', array_column($this->getJson('/api/v1/cancel-reasons')->assertJsonPath('data.merchant', false)->json('data.reasons'), 'code'));
    }

    public function test_refusing_an_order_needs_a_reason_too(): void
    {
        $order = $this->createOrder();
        $order->forceFill(['status' => 'pending'])->save();

        $this->cancel($this->admin, $order, [], 'rejected')->assertJsonValidationErrors('cancel_reason_code');
        $this->cancel($this->admin, $order, ['cancel_reason_code' => 'out_of_zone'], 'rejected')
            ->assertOk()
            ->assertJsonPath('data.cancellation.rejected', true)
            ->assertJsonPath('data.cancellation.reason_label', 'Adresse hors zone');
    }

    public function test_bulk_cancel_needs_one_reason_for_all(): void
    {
        Sanctum::actingAs($this->admin);
        $order = $this->createOrder();

        $this->postJson('/api/v1/orders/bulk-cancel', ['order_ids' => [$order->id]])->assertJsonValidationErrors('cancel_reason_code');
        $this->postJson('/api/v1/orders/bulk-cancel', ['order_ids' => [$order->id], 'reason_code' => 'not_treated'])
            ->assertOk()->assertJsonPath('data.cancelled', [$order->id]);
        $this->assertSame(['not_treated', 'company'], [$order->fresh()->cancel_reason_code, $order->fresh()->cancelled_by_type]);
    }
}
