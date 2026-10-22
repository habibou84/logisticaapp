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
}
