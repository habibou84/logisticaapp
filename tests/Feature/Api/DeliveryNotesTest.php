<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\BillingPlan;
use App\Models\Destination;
use App\Models\IncidentReason;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Notes de livraison de la distribution et de la MAD : motifs du livreur par famille et par activité, notes de suivi
 * de l'équipe, reconduction d'une course, réglage des motifs.
 */
class DeliveryNotesTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo('2026-11-23 10:00:00');
        foreach ([$this->cocody, $this->yopougon] as $zone) {
            $zone->update(['in_forfait' => true]);
        }
        Sanctum::actingAs($this->admin);
        $id = $this->postJson("/api/v1/merchants/{$this->merchant->id}/billing-plans", [
            'formula' => 'distribution_contract', 'monthly_fee' => 50000, 'included_quantity' => 100, 'overage_price' => 800,
            'delivery_delay' => '48h', 'cheque_price' => 1500, 'extra_kg_price' => 300, 'starts_on' => '2026-11-01',
        ])->assertCreated()->json('data.id');
        $this->merchant->syncActivities([BillingPlan::findOrFail($id)->activity]);
    }

    private function reason(string $code): IncidentReason
    {
        return IncidentReason::whereNull('company_id')->where('code', $code)->sole();
    }

    private function outForDelivery(array $data = []): Order
    {
        $order = $this->createOrder(['activity' => 'distribution', 'shipment_type' => 'pli', ...$data]);
        $order->forceFill(['status' => OrderStatus::OutForDelivery])->save();

        return $order;
    }

    private function failWith(Order $order, string $code, array $extra = [])
    {
        Sanctum::actingAs($this->dispatcher);

        return $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivery_failed', 'incident_reason_id' => $this->reason($code)->id, ...$extra]);
    }

    public function test_reasons_follow_the_activity_and_their_family(): void
    {
        Sanctum::actingAs($this->courierA->user);
        $reasons = collect($this->getJson('/api/v1/incident-reasons')->assertOk()->json('data'));
        $this->assertSame('documents', $reasons->firstWhere('code', 'd_no_stamp')['family']);
        $this->assertSame(['distribution', 'mad'], $reasons->firstWhere('code', 'd_no_stamp')['activities']);
        // Les notes de suivi restent à l'équipe
        $this->assertNull($reasons->firstWhere('code', 'f_waiting_address'));

        // Motif de l'e-commerce sur une course de distribution : refusé ; « Autres » sans note : refusé
        $order = $this->outForDelivery();
        $this->failWith($order, 'no_money')->assertStatus(422)->assertJsonValidationErrors('incident_reason_id');
        $this->failWith($order, 'd_other')->assertStatus(422)->assertJsonValidationErrors('note');
        $this->failWith($order, 'd_other', ['note' => 'Gardien absent, portail fermé'])->assertOk();
    }

    public function test_a_document_problem_sends_the_fold_back_and_it_can_be_renewed(): void
    {
        $order = $this->outForDelivery();
        $this->failWith($order, 'd_no_stamp')->assertOk()->assertJsonPath('data.status', 'delivery_failed')->assertJsonPath('data.return_requested', true);

        // Revenu au client : la course reste comptée ; reconduite plus tard (nouvelle course liée)
        Order::whereKey($order->id)->update(['status' => OrderStatus::Returned]);
        Sanctum::actingAs($this->merchantUser);
        $renewed = $this->postJson("/api/v1/orders/{$order->id}/renew", ['date' => '2026-11-25', 'note' => 'Cachet ajouté'])->assertCreated()
            ->assertJsonPath('data.renewed_from.tracking_code', $order->tracking_code)
            ->assertJsonPath('data.recipient.name', $order->recipient_name)->json('data');
        $this->assertNotSame($order->id, $renewed['id']);
        $this->assertSame(2, Order::where('activity', 'distribution')->whereNotIn('status', ['cancelled', 'rejected'])->count());

        // Une course livrée ne se reconduit pas
        Order::whereKey($renewed['id'])->update(['status' => OrderStatus::Delivered]);
        $this->postJson("/api/v1/orders/{$renewed['id']}/renew")->assertStatus(422);
    }

    public function test_reasons_update_the_directory(): void
    {
        $destination = Destination::create(['company_id' => $this->company->id, 'name' => 'Imperial Tobacco', 'zone_id' => $this->yopougon->id, 'phone' => '0707070707', 'status' => 'active', 'source' => 'manual']);
        $this->failWith($this->outForDelivery(['destination_id' => $destination->id]), 'd_company_gone')->assertOk();
        $this->assertSame('inactive', $destination->fresh()->status);

        $this->failWith($this->outForDelivery(['destination_id' => $destination->id]), 'd_wrong_number')->assertOk();
        $this->assertTrue($destination->fresh()->phone_check);
    }

    public function test_team_follow_up_notes(): void
    {
        $order = $this->createOrder(['activity' => 'distribution', 'shipment_type' => 'pli']);
        $order->forceFill(['status' => OrderStatus::Confirmed])->save();

        // Réservées à l'équipe
        Sanctum::actingAs($this->merchantUser);
        $this->postJson("/api/v1/orders/{$order->id}/follow-up", ['reason_id' => $this->reason('f_waiting_address')->id])->assertForbidden();

        // « En attente de l'adresse » : mise en attente, hors de la file « sans livreur »
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/follow-up", ['reason_id' => $this->reason('f_waiting_address')->id, 'note' => 'Le client cherche le bon site'])
            ->assertOk()->assertJsonPath('data.follow_up.label', 'En attente de l\'adresse')->assertJsonPath('data.follow_up.holds', true);
        $this->assertContains($order->id, collect($this->getJson('/api/v1/orders?queue=on_hold')->json('data'))->pluck('id')->all());
        $this->assertNotContains($order->id, collect($this->getJson('/api/v1/orders?queue=unassigned')->json('data'))->pluck('id')->all());
        // Le client ne voit pas les notes de l'équipe (sauf réglage)
        Sanctum::actingAs($this->merchantUser);
        $this->getJson("/api/v1/orders/{$order->id}")->assertJsonPath('data.follow_up', null);

        // « Réceptionne le » : date obligatoire ; la livraison échouée passe au report daté
        $order->forceFill(['status' => OrderStatus::DeliveryFailed])->save();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/follow-up", ['reason_id' => $this->reason('f_receives_on')->id])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->postJson("/api/v1/orders/{$order->id}/follow-up", ['reason_id' => $this->reason('f_receives_on')->id, 'date' => '2026-11-27'])
            ->assertOk()->assertJsonPath('data.status', 'rescheduled')->assertJsonPath('data.delivery.scheduled_date', '2026-11-27');

        // Levée
        $this->postJson("/api/v1/orders/{$order->id}/follow-up", ['reason_id' => null])->assertOk()->assertJsonPath('data.follow_up', null);
    }

    public function test_the_administrator_sets_the_reasons(): void
    {
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/incident-reason-settings')->assertForbidden();

        Sanctum::actingAs($this->admin);
        $stamp = $this->reason('d_no_stamp');
        $this->patchJson("/api/v1/incident-reason-settings/{$stamp->id}", ['label' => 'Cachet absent', 'photo' => 'required'])->assertOk()->assertJsonPath('data.photo', 'required');
        $this->patchJson("/api/v1/incident-reason-settings/{$this->reason('d_inventory')->id}", ['is_active' => false])->assertOk();
        $new = $this->postJson('/api/v1/incident-reason-settings', ['label' => 'Courrier non conforme', 'applies_to' => 'delivery', 'family' => 'documents', 'activities' => ['distribution'], 'triggers_return' => true])
            ->assertCreated()->json('data');
        $this->deleteJson("/api/v1/incident-reason-settings/{$stamp->id}")->assertForbidden();

        $codes = collect($this->getJson('/api/v1/incident-reasons')->json('data'))->pluck('label');
        $this->assertContains('Cachet absent', $codes);
        $this->assertContains('Courrier non conforme', $codes);
        $this->assertNotContains('En inventaire', $codes);

        // Photo désormais exigée : sans photo, refusé
        $this->failWith($this->outForDelivery(), 'd_no_stamp')->assertStatus(422)->assertJsonValidationErrors('photo');
        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/v1/incident-reason-settings/{$new['id']}")->assertNoContent();
    }
}
