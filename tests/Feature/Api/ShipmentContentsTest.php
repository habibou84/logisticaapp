<?php

namespace Tests\Feature\Api;

use App\Models\BillingPlan;
use App\Models\Merchant;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Chèques, plis et colis remis ensemble par le client : en distribution, une course par type (reliées, facturées
 * chacune selon son type) ; en MAD, une seule course qui garde le détail des envois.
 */
class ShipmentContentsTest extends TestCase
{
    use BuildsDeliveryWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo('2026-11-10 10:00:00');
        foreach ([$this->cocody, $this->yopougon, $this->plateau] as $zone) {
            $zone->update(['in_forfait' => true]);
        }
    }

    private function plan(Merchant $merchant, array $attributes): BillingPlan
    {
        $merchant->syncActivities([str_starts_with($attributes['formula'], 'mad') ? 'mad' : 'distribution']);
        Sanctum::actingAs($this->admin);
        $id = $this->postJson("/api/v1/merchants/{$merchant->id}/billing-plans", [
            'monthly_fee' => 50000,
            'included_quantity' => 50,
            'overage_price' => 800,
            'delivery_delay' => '48h',
            'cheque_price' => 1500,
            'extra_kg_price' => 300,
            'starts_on' => '2026-11-01',
            ...$attributes,
        ])->assertCreated()->json('data.id');

        return BillingPlan::findOrFail($id);
    }

    private function payload(string $activity, array $contents, array $extra = []): array
    {
        return [
            'merchant_id' => $this->merchant->id,
            'activity' => $activity,
            'recipient_name' => 'Jean Kouadio',
            'recipient_phone' => '0707070707',
            'delivery_zone_id' => $this->yopougon->id,
            'delivery_address' => 'Yopougon Siporex',
            'shipment_contents' => $contents,
            ...$extra,
        ];
    }

    public function test_distribution_creates_one_linked_course_per_shipment_type(): void
    {
        $this->plan($this->merchant, ['formula' => 'distribution_contract']);
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/orders', $this->payload('distribution', [
            ['type' => 'colis', 'quantity' => 4],
            ['type' => 'pli', 'quantity' => 5],
            ['type' => 'cheque', 'quantity' => 3],
        ], ['weight_kg' => 7, 'declared_value' => 20000, 'description' => 'Dossiers', 'pli_kind' => 'Facture']))->assertCreated();

        // Ordre chèque, pli, colis ; la première course est renvoyée, les trois sont listées
        $this->assertSame(['cheque', 'pli', 'colis'], array_column($response->json('group'), 'shipment_type'));
        $this->assertSame($response->json('group.0.id'), $response->json('data.id'));

        $orders = Order::query()->orderBy('id')->get();
        $this->assertCount(3, $orders);
        $this->assertCount(1, $orders->pluck('shipment_group')->unique());
        $this->assertNotNull($orders[0]->shipment_group);
        [$cheque, $pli, $colis] = $orders;

        // Chaque course est facturée selon son type : chèques à l'unité, plis et colis dans le forfait
        $this->assertSame([3, 3, 4500, 'cheque'], [$cheque->shipment_quantity, $cheque->billing_units, $cheque->billing_amount, $cheque->billing_basis]);
        $this->assertSame([5, 5, 'forfait', 'Facture'], [$pli->shipment_quantity, $pli->billing_units, $pli->billing_basis, $pli->pli_kind]);
        // Un colis compte pour un ; poids, contenu et valeur vont au colis
        $this->assertSame([1, 1, 20000, 'Dossiers'], [$colis->shipment_quantity, $colis->billing_units, $colis->declared_value, $colis->description]);
        $this->assertEquals(7, $colis->weight_kg);
        $this->assertNull($pli->weight_kg);
        $this->assertNull($pli->declared_value);
        $this->assertNull($cheque->shipment_contents);

        // Détail d'une course : les autres courses de la même saisie
        $this->getJson("/api/v1/orders/{$pli->id}")->assertOk()
            ->assertJsonPath('group.2.id', $colis->id)
            ->assertJsonPath('data.package.shipment_group', $pli->shipment_group);
    }

    public function test_mad_keeps_cheques_plis_and_colis_on_a_single_course(): void
    {
        $this->plan($this->merchant, ['formula' => 'mad_contract']);
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/orders', $this->payload('mad', [
            ['type' => 'pli', 'quantity' => 5],
            ['type' => 'colis', 'quantity' => 1],
            ['type' => 'cheque', 'quantity' => 2],
        ], ['pli_kind' => 'Courrier']))->assertCreated()
            ->assertJsonPath('group', [])
            ->assertJsonPath('data.package.shipment_contents.0.type', 'cheque')
            ->assertJsonPath('data.package.shipment_contents.1.quantity', 5)
            ->assertJsonPath('data.package.shipment_contents.2.label', 'Colis');

        $order = Order::sole();
        $this->assertSame($response->json('data.id'), $order->id);
        $this->assertSame('cheque', $order->shipment_type->value);
        $this->assertSame(2, $order->shipment_quantity);
        // Le type de pli est gardé : la course contient des plis
        $this->assertSame('Courrier', $order->pli_kind);
        $this->assertNull($order->shipment_group);

        // Type changé à la main : le détail n'est plus à jour
        $order->update(['shipment_type' => 'colis']);
        $this->assertNull($order->fresh()->shipment_contents);
    }

    public function test_single_type_or_ecommerce_creates_an_ordinary_course(): void
    {
        $this->plan($this->merchant, ['formula' => 'distribution_contract']);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/orders', $this->payload('distribution', [['type' => 'pli', 'quantity' => 4]]))->assertCreated()
            ->assertJsonPath('data.package.shipment_type', 'pli')
            ->assertJsonPath('data.package.shipment_quantity', 4)
            ->assertJsonPath('data.package.shipment_contents', []);
        $this->assertNull(Order::sole()->shipment_group);

        // Deux fois le même type : refusé
        $this->postJson('/api/v1/orders', $this->payload('distribution', [['type' => 'pli'], ['type' => 'pli']]))
            ->assertUnprocessable()->assertJsonValidationErrors('shipment_contents.0.type');
    }
}
