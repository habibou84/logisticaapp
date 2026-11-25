<?php

namespace Tests\Feature\Api;

use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Page Clients : liste paginée, filtres (statut, arrivée, zone, position), tri et résumé.
 */
class MerchantDirectoryTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Sanctum::actingAs($this->admin);
    }

    private function merchant(array $data): Merchant
    {
        $merchant = (new Merchant)->forceFill(['company_id' => $this->company->id, 'phone' => '07'.random_int(10000000, 99999999), 'status' => 'active', ...$data]);
        $merchant->save();

        return $merchant;
    }

    public function test_merchants_are_paginated_filtered_sorted_and_summarised(): void
    {
        $this->merchant(['business_name' => 'Zeta Shop', 'pickup_zone_id' => $this->cocody->id, 'pickup_lat' => 5.3, 'pickup_lng' => -4.0]);
        $this->merchant(['business_name' => 'Alpha Mode', 'status' => 'suspended']);
        $this->merchant(['business_name' => 'Beta Beauté', 'source' => 'signup']);
        $total = Merchant::count();

        $this->getJson('/api/v1/merchants?per_page=2&summary=1')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', $total)->assertJsonPath('meta.last_page', (int) ceil($total / 2))
            ->assertJsonPath('summary.merchants', $total)->assertJsonPath('summary.suspended', 1)->assertJsonPath('summary.signups_new', 1)
            ->assertJsonPath('data.0.business_name', 'Alpha Mode');

        $this->getJson('/api/v1/merchants?status=suspended')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/merchants?source=signup')->assertJsonPath('data.0.business_name', 'Beta Beauté')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/merchants?zone_id={$this->cocody->id}&located=1")->assertJsonCount(1, 'data')->assertJsonPath('data.0.business_name', 'Zeta Shop');
        $this->assertNotContains('Zeta Shop', $this->getJson('/api/v1/merchants?located=0')->json('data.*.business_name'));
        $this->getJson('/api/v1/merchants?sort=name&dir=desc')->assertJsonPath('data.0.business_name', 'Zeta Shop');
        $this->getJson('/api/v1/merchants?sort=created_at&dir=desc')->assertJsonPath('data.0.business_name', 'Beta Beauté');
        $this->getJson('/api/v1/merchants?sort=pirate')->assertJsonValidationErrors('sort');
        // Sans résumé demandé : rien en plus (sélecteurs des autres écrans)
        $this->assertNull($this->getJson('/api/v1/merchants')->json('summary'));
    }
}
