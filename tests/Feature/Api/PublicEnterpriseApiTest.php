<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\BillingPlan;
use App\Models\Department;
use App\Models\Destination;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * API publique pour une entreprise sous contrat (distribution) : son logiciel lit ses départements et l'annuaire,
 * puis envoie un pli sans retaper l'adresse.
 */
class PublicEnterpriseApiTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private string $key;

    private Department $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo('2026-11-10 10:00:00');
        foreach ([$this->cocody, $this->yopougon, $this->plateau] as $zone) {
            $zone->update(['in_forfait' => true]);
        }
        Sanctum::actingAs($this->admin);
        $plan = $this->postJson("/api/v1/merchants/{$this->merchant->id}/billing-plans", [
            'formula' => 'distribution_contract', 'monthly_fee' => 50000, 'included_quantity' => 100, 'overage_price' => 800,
            'delivery_delay' => '48h', 'cheque_price' => 1500, 'extra_kg_price' => 300, 'starts_on' => '2026-11-01',
        ])->assertCreated()->json('data.id');
        $this->merchant->syncActivities([BillingPlan::findOrFail($plan)->activity]);
        $id = $this->postJson("/api/v1/merchants/{$this->merchant->id}/departments", ['name' => 'Finance', 'code' => 'fin'])->assertCreated()->json('data.id');
        $this->finance = Department::findOrFail($id);
        $this->app['auth']->forgetGuards();
        [, $this->key] = ApiKey::issue($this->merchant->fresh(), 'Logiciel courrier', ['orders:read', 'orders:write'], $this->merchantUser);
    }

    private function api(string $method, string $uri, array $data = [], array $headers = [])
    {
        return $this->json($method, '/api/public/v1/'.$uri, $data, ['Authorization' => 'Bearer '.$this->key, ...$headers]);
    }

    public function test_the_client_software_reads_departments_and_the_directory(): void
    {
        $this->api('GET', 'departments')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->finance->id)->assertJsonPath('data.0.code', 'FIN');

        Destination::create(['company_id' => $this->company->id, 'name' => 'Banque Atlantique', 'zone_id' => $this->plateau->id, 'address' => 'Avenue Noguès', 'phone' => '+2250707000001']);
        $this->api('GET', 'destinations?q=atlantique')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Banque Atlantique')->assertJsonPath('data.0.zone_name', $this->plateau->name)
            // Ni téléphone ni position : l'annuaire est partagé entre les clients
            ->assertJsonMissingPath('data.0.phone')->assertJsonMissingPath('data.0.lat');
        $this->api('GET', 'destinations')->assertJsonValidationErrors('q');
    }

    public function test_the_client_software_sends_a_pli(): void
    {
        $destination = Destination::create(['company_id' => $this->company->id, 'name' => 'Banque Atlantique', 'zone_id' => $this->plateau->id, 'address' => 'Avenue Noguès', 'phone' => '+2250707000001']);

        $created = $this->api('POST', 'orders', [
            'merchant_reference' => 'COURRIER-2026-118',
            'activity' => 'distribution', 'shipment_type' => 'pli', 'shipment_quantity' => 3, 'pli_kind' => 'Factures',
            'department_id' => $this->finance->id, 'destination_id' => $destination->id, 'requested_by' => 'Mme Koné',
        ], ['Idempotency-Key' => 'pli-118'])->assertCreated()
            ->assertJsonPath('data.activity', 'distribution')
            ->assertJsonPath('data.shipment.type', 'pli')
            ->assertJsonPath('data.shipment.quantity', 3)
            ->assertJsonPath('data.shipment.kind', 'Factures')
            ->assertJsonPath('data.shipment.department_id', $this->finance->id)
            ->assertJsonPath('data.shipment.destination_id', $destination->id)
            // L'adresse vient de l'annuaire
            ->assertJsonPath('data.delivery.zone_id', $this->plateau->id)
            ->assertJsonPath('data.delivery.address', 'Avenue Noguès');

        $order = Order::where('tracking_code', $created->json('data.tracking_code'))->firstOrFail();
        // Compris dans le forfait du mois : rien à encaisser chez le destinataire
        $this->assertSame([0, 'api'], [(int) $order->cod_amount, $order->source]);

        // Relu par le logiciel
        $this->api('GET', 'orders/'.$order->tracking_code)->assertOk()->assertJsonPath('data.shipment.delivery_delay', $order->delivery_delay?->value);
    }
}
