<?php

namespace Tests\Feature\Api;

use App\Enums\SurchargeType;
use App\Models\Company;
use App\Models\PricingGrid;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

class PricingTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function price($from, $to, array $options = []): int
    {
        return app(PricingService::class)->quote($this->merchant, $from, $to, $options)->total();
    }

    public function test_same_commune_and_symmetric_routes(): void
    {
        $this->assertSame(1000, $this->price($this->cocody, $this->cocody));
        $this->assertSame(1500, $this->price($this->cocody, $this->yopougon));
        $this->assertSame(1500, $this->price($this->yopougon, $this->cocody));
    }

    public function test_non_symmetric_rule_only_applies_in_its_direction(): void
    {
        $this->rule($this->grid, $this->plateau, $this->cocody, 2500, symmetric: false);

        $this->assertSame(2500, $this->price($this->plateau, $this->cocody));

        $this->expectExceptionMessage('Aucun tarif défini pour le trajet Cocody → Plateau.');
        $this->price($this->cocody, $this->plateau);
    }

    public function test_neighbourhood_falls_back_to_its_commune_unless_it_has_its_own_rule(): void
    {
        $this->assertSame(1500, $this->price($this->angre, $this->yopougon));

        $this->rule($this->grid, $this->angre, $this->yopougon, 1800);

        $this->assertSame(1800, $this->price($this->angre, $this->yopougon));
        $this->assertSame(1500, $this->price($this->cocody, $this->yopougon));
    }

    public function test_negotiated_grid_overrides_and_falls_back_to_default(): void
    {
        $vip = PricingGrid::factory()->create(['company_id' => $this->company->id, 'name' => 'Gros volume']);
        $this->rule($vip, $this->cocody, $this->yopougon, 1200);
        $this->merchant->update(['pricing_grid_id' => $vip->id]);

        $this->assertSame(1200, $this->price($this->cocody, $this->yopougon));
        $this->assertSame(1000, $this->price($this->cocody, $this->cocody)); // absent de la grille négociée
    }

    public function test_surcharges_for_express_and_weight(): void
    {
        $this->grid->surcharges()->create(['type' => SurchargeType::Express, 'amount' => 1000]);
        $this->grid->surcharges()->create(['type' => SurchargeType::Weight, 'min_value' => 10, 'max_value' => 20, 'amount' => 500]);
        $this->grid->surcharges()->create(['type' => SurchargeType::Weight, 'min_value' => 20, 'percent' => 50]);

        $this->assertSame(1500, $this->price($this->cocody, $this->yopougon, ['weight_kg' => 5]));
        $this->assertSame(2500, $this->price($this->cocody, $this->yopougon, ['is_express' => true]));
        $this->assertSame(2000, $this->price($this->cocody, $this->yopougon, ['weight_kg' => 12]));
        $this->assertSame(2250, $this->price($this->cocody, $this->yopougon, ['weight_kg' => 25]));
    }

    public function test_quote_endpoint_for_a_merchant(): void
    {
        Sanctum::actingAs($this->merchantUser);

        $this->postJson('/api/v1/quotes', ['delivery_zone_id' => $this->yopougon->id])
            ->assertOk()
            ->assertJsonPath('data.total', 1500);

        $this->postJson('/api/v1/quotes', ['delivery_zone_id' => $this->plateau->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.delivery_zone_id.0', 'Aucun tarif défini pour le trajet Cocody → Plateau.');
    }

    public function test_quote_rejects_zones_of_another_company(): void
    {
        $foreign = $this->zone('Ailleurs', company: Company::factory()->create());
        Sanctum::actingAs($this->merchantUser);

        $this->postJson('/api/v1/quotes', ['delivery_zone_id' => $foreign->id])
            ->assertJsonValidationErrors('delivery_zone_id');
    }

    public function test_base_price_covers_any_route_without_a_price(): void
    {
        Sanctum::actingAs($this->admin);
        // Paliers de la commune de départ gardés avec la grille ; une zone inconnue est ignorée
        $this->putJson("/api/v1/pricing-grids/{$this->grid->id}", ['base_price' => 2000, 'tiers' => [$this->cocody->id => [1000, 1500, 2000, 3000], 999999 => [1]]])
            ->assertOk()
            ->assertJsonPath('data.base_price', 2000)
            ->assertJsonPath("data.tiers.{$this->cocody->id}", [1000, 1500, 2000, 3000])
            ->assertJsonMissingPath('data.tiers.999999');

        // Trajet réglé : son prix ; sans prix : le prix de base, suppléments compris
        $this->assertSame(1500, $this->price($this->cocody, $this->yopougon));
        $this->assertSame(2000, $this->price($this->cocody, $this->plateau));
        $this->grid->surcharges()->create(['type' => SurchargeType::Express, 'amount' => 500]);
        $this->assertSame(2500, $this->price($this->cocody, $this->plateau, ['is_express' => true]));

        $this->putJson("/api/v1/pricing-grids/{$this->grid->id}", ['base_price' => null])->assertOk()->assertJsonPath('data.base_price', null);
    }

    public function test_a_commune_can_be_delivered_without_being_collected(): void
    {
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/zones/{$this->cocody->id}", ['is_pickup' => false])->assertOk()->assertJsonPath('data.is_pickup', false);
        // Le quartier suit sa commune
        $zones = collect($this->getJson('/api/v1/zones')->json('data'))->keyBy('id');
        $this->assertFalse($zones[$this->angre->id]['is_pickup']);
        $this->assertTrue($zones[$this->yopougon->id]['is_pickup']);

        Sanctum::actingAs($this->merchantUser);
        $this->postJson('/api/v1/orders', [
            'recipient_name' => 'Awa', 'recipient_phone' => '0707070707', 'delivery_zone_id' => $this->yopougon->id,
            'delivery_address' => 'Rue 12', 'items_amount' => 5000, 'fee_payer' => 'merchant',
        ])->assertJsonValidationErrors('pickup_zone_id');
    }
}
