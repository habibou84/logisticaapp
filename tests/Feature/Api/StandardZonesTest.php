<?php

namespace Tests\Feature\Api;

use App\Models\InteriorRate;
use App\Models\Zone;
use App\Services\Zones\PlaceList;
use App\Services\Zones\StandardZones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Jibiat\Socle\Places\Places;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Zones standard : communes, quartiers et villes viennent de la liste des lieux ; les zones existantes y sont
 * rattachées sans doublon, les villes de l'intérieur arrivent masquées sauf si elles ont un tarif.
 */
class StandardZonesTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_existing_zones_are_linked_and_missing_places_added_once(): void
    {
        $bouake = $this->zone('Bouaké');
        $bouake->update(['city' => 'Bouaké']);
        InteriorRate::create(['company_id' => $this->company->id, 'city' => 'Korhogo', 'delivery_delay' => '24h', 'pli_price' => 2000, 'colis_price' => 3000, 'extra_kg_price' => 0]);

        $counts = app(StandardZones::class)->sync($this->company);

        // Communes et quartier déjà saisis : rattachés, pas recréés
        $this->assertSame('abidjan-cocody', $this->cocody->fresh()->place_code);
        $this->assertSame('abidjan-cocody-angre', $this->angre->fresh()->place_code);
        $this->assertSame('bouake', $bouake->fresh()->place_code);
        $this->assertSame(1, Zone::where('name', 'Cocody')->count());
        $this->assertSame(5, $counts['linked']);

        // Quartiers ajoutés sous leur commune, livrés comme elle ; villes masquées sauf celle qui a un tarif
        $riviera = Zone::where('place_code', 'abidjan-cocody-riviera-2')->sole();
        $this->assertSame([$this->cocody->id, 'Abidjan', true, true], [$riviera->parent_id, $riviera->city, $riviera->is_active, $riviera->is_pickup]);
        $this->assertSame([false, false], [Zone::where('place_code', 'man')->value('is_active'), Zone::where('place_code', 'man')->value('is_pickup')]);
        $this->assertTrue(Zone::where('place_code', 'korhogo')->value('is_active'));
        $this->assertSame(['Korhogo', true], [Zone::where('place_code', 'korhogo-soba')->value('city'), Zone::where('place_code', 'korhogo-soba')->value('is_active')]);

        // Villes en plus de la liste commune : présentes, mais pas envoyées comme lieu commun
        $this->assertSame('Katiola', Zone::where('place_code', 'katiola')->value('name'));
        $this->assertSame('Tengréla', Zone::where('place_code', 'tengrela')->value('name'));
        $this->assertFalse(PlaceList::isCommon('tengrela'));

        $again = app(StandardZones::class)->sync($this->company);
        $this->assertSame(['created' => 0, 'linked' => 0], $again);
        $this->assertSame(count(PlaceList::all()) - 1, Zone::whereNotNull('place_code')->count());
    }

    public function test_the_zones_screen_completes_the_list_and_protects_standard_places(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/zones/standard')->assertOk()->assertJsonPath('data.linked', 4);
        $zones = collect($this->getJson('/api/v1/zones?include_inactive=1')->assertOk()->json('data'));
        $this->assertSame(['abidjan-cocody', true], [$zones->firstWhere('id', $this->cocody->id)['place'], $zones->firstWhere('id', $this->cocody->id)['is_standard']]);
        $this->assertNull($zones->firstWhere('name', 'Tengréla')['place']);

        // Un lieu de la liste garde son nom et ne se supprime pas : il se masque
        $this->patchJson("/api/v1/zones/{$this->cocody->id}", ['name' => 'Kokody'])->assertStatus(422);
        $this->deleteJson("/api/v1/zones/{$this->cocody->id}")->assertStatus(422);
        $this->patchJson("/api/v1/zones/{$this->cocody->id}", ['is_active' => false, 'in_forfait' => true])->assertOk();

        // Lieu propre à l'entreprise : libre
        $own = $this->postJson('/api/v1/zones', ['name' => 'Cité Synacass-ci', 'parent_id' => $this->yopougon->id])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/zones/{$own}", ['name' => 'Synacass-ci'])->assertOk();

        $cities = $this->getJson('/api/v1/zones/cities')->assertOk()->json('data');
        $this->assertContains('Bouaké', $cities);
        $this->assertContains('Katiola', $cities);
        $this->assertNotContains('Abidjan', $cities);
        // Liste commune complétée : plus de cent villes
        $this->assertGreaterThan(count(collect(Places::all())->where('type', Places::CITY)) + 50, count($cities));
    }

    public function test_a_city_rate_opens_its_hidden_city(): void
    {
        app(StandardZones::class)->sync($this->company);
        $this->assertFalse(Zone::where('place_code', 'daloa')->value('is_active'));
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/interior-rates', ['city' => 'daloa', 'delivery_delay' => '48h', 'pli_price' => 2000, 'colis_price' => 3000, 'extra_kg_price' => 500])->assertSuccessful();

        $this->assertTrue(Zone::where('place_code', 'daloa')->value('is_active'));
        $this->assertSame(1, Zone::where('name', 'Daloa')->count());
    }
}
