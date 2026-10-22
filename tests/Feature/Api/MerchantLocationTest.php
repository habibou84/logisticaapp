<?php

namespace Tests\Feature\Api;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Services\Merchants\GeoLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Position de ramassage des marchands : par le marchand, par l'agence, et apprise
 * d'après les ramassages des livreurs.
 */
class MerchantLocationTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /**
     * Course validée, ramassée par le livreur A à la position donnée.
     */
    private function pickUpAt(float $lat, float $lng, ?Merchant $merchant = null): Order
    {
        $order = $this->createOrder([], null, $merchant);
        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id])->assertSuccessful();
        $this->as($this->courierA->user)->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up', 'lat' => $lat, 'lng' => $lng])->assertOk();

        return $order;
    }

    public function test_merchant_saves_the_pickup_location_from_the_shop(): void
    {
        $this->as($this->merchantUser)->getJson('/api/v1/auth/me')->assertJsonPath('data.merchant.has_pickup_location', false);

        $this->putJson('/api/v1/merchant/pickup-location', [
            'lat' => 5.3631, 'lng' => -3.9735, 'accuracy' => 12,
            'pickup_address' => 'Riviera 2, rue des Jardins', 'pickup_landmark' => 'Portail bleu',
        ])->assertOk()
            ->assertJsonPath('data.pickup_location_source', 'merchant')
            ->assertJsonPath('data.pickup_location_accuracy', 12)
            ->assertJsonPath('data.pickup_landmark', 'Portail bleu');

        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.merchant.has_pickup_location', true);
        $this->assertSame([5.3631, -3.9735], [$this->merchant->fresh()->pickup_lat, $this->merchant->fresh()->pickup_lng]);

        // Nouvelle course : la position est reprise
        $this->assertSame(5.3631, (float) $this->createOrder([], null, $this->merchant->fresh())->pickup_lat);

        $this->putJson('/api/v1/merchant/pickup-location', ['lat' => 120, 'lng' => 0])->assertJsonValidationErrors('lat');
        $this->as($this->dispatcher)->getJson('/api/v1/merchant/pickup-location')->assertForbidden();
    }

    public function test_staff_place_or_remove_the_location(): void
    {
        $this->as($this->admin)->putJson("/api/v1/merchants/{$this->merchant->id}/pickup-location", ['lat' => 5.36, 'lng' => -3.97])
            ->assertOk()->assertJsonPath('data.pickup_location_source', 'staff');
        $this->getJson("/api/v1/merchants/{$this->merchant->id}")->assertJsonPath('data.pickup_location_source', 'staff');

        $this->putJson("/api/v1/merchants/{$this->merchant->id}/pickup-location", ['lat' => null, 'lng' => null])
            ->assertOk()->assertJsonPath('data.pickup_lat', null)->assertJsonPath('data.pickup_location_source', null);

        // Par la fiche (API) aussi : la source est notée
        $this->patchJson("/api/v1/merchants/{$this->merchant->id}", ['pickup_lat' => 5.1, 'pickup_lng' => -4.1])->assertOk()
            ->assertJsonPath('data.pickup_location_source', 'staff');

        $this->as($this->merchantUser)->putJson("/api/v1/merchants/{$this->merchant->id}/pickup-location", ['lat' => 5.36, 'lng' => -3.97])->assertForbidden();
    }

    public function test_location_is_learned_from_courier_pickups(): void
    {
        // Un seul ramassage : pas assez
        $this->pickUpAt(5.36000, -3.97000);
        $this->assertNull($this->merchant->fresh()->pickup_lat);

        // Deuxième ramassage à ~30 m : position estimée ; un ramassage lointain ne compte pas
        $this->pickUpAt(5.36027, -3.97000);
        $this->pickUpAt(5.40000, -3.90000);
        $merchant = $this->merchant->fresh();
        $this->assertSame('courier', $merchant->pickup_location_source);
        $this->assertEqualsWithDelta(5.360135, $merchant->pickup_lat, 0.00001);
        $this->assertEqualsWithDelta(-3.97, $merchant->pickup_lng, 0.00001);
        $this->assertSame(15, $merchant->pickup_location_accuracy);

        // Position confirmée par le marchand : les livreurs ne la changent plus
        $this->as($this->merchantUser)->putJson('/api/v1/merchant/pickup-location', ['lat' => 5.3605, 'lng' => -3.9705])->assertOk();
        $this->pickUpAt(5.36001, -3.97001);
        $this->assertSame([5.3605, -3.9705, 'merchant'], [$this->merchant->fresh()->pickup_lat, $this->merchant->fresh()->pickup_lng, $this->merchant->fresh()->pickup_location_source]);
    }

    public function test_backfill_command_locates_merchants_from_past_pickups(): void
    {
        $other = Merchant::factory()->create(['company_id' => $this->company->id, 'pickup_zone_id' => $this->cocody->id]);
        $this->pickUpAt(5.30, -4.00, $other);
        $this->pickUpAt(5.30001, -4.00001, $other);
        // Effacée pour simuler un historique antérieur à la fonctionnalité
        $other->forceFill(['pickup_lat' => null, 'pickup_lng' => null, 'pickup_location_source' => null])->save();

        $this->artisan('merchants:locate')->expectsOutput('1 marchand(s) localisé(s) d\'après les ramassages.')->assertSuccessful();
        $this->assertSame('courier', $other->fresh()->pickup_location_source);
        $this->assertNull($this->merchant->fresh()->pickup_lat);
    }

    public function test_map_links_and_written_coordinates_are_read(): void
    {
        $links = app(GeoLink::class);

        $this->assertSame(['lat' => 5.3631, 'lng' => -3.9735], $links->parse('https://www.google.com/maps/place/Riviera/@5.36,-3.97,17z/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d5.3631!4d-3.9735'));
        $this->assertSame(['lat' => 5.36, 'lng' => -3.97], $links->parse('https://www.google.com/maps/@5.36,-3.97,15z'));
        $this->assertSame(['lat' => 5.345, 'lng' => -4.024], $links->parse('https://maps.google.com/?q=5.345,-4.024'));
        $this->assertSame(['lat' => 5.345, 'lng' => -4.024], $links->parse('https://www.openstreetmap.org/?mlat=5.345&mlon=-4.024#map=17/5.345/-4.024'));
        $this->assertSame(['lat' => 5.345, 'lng' => -4.024], $links->parse(' 5.345, -4.024 '));
        $this->assertNull($links->parse('Riviera 2 face pharmacie'));

        // Lien court partagé depuis le téléphone : redirection suivie
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, ['Location' => 'https://www.google.com/maps/place/Boutique/@5.3,-3.9,17z/data=!3d5.3631!4d-3.9735']),
        ]);
        $this->as($this->merchantUser)->postJson('/api/v1/geo/link', ['link' => 'https://maps.app.goo.gl/AbCdEf123'])
            ->assertOk()->assertJsonPath('data', ['lat' => 5.3631, 'lng' => -3.9735]);

        // Autre site : jamais contacté
        $this->postJson('/api/v1/geo/link', ['link' => 'https://exemple.com/redirige'])->assertJsonValidationErrors('link');
        Http::assertSentCount(1);
    }
}
