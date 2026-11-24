<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Page Livreurs › Nouveau livreur : le compte créé renvoie son profil livreur, ouvert ensuite (véhicule, zones).
 */
class NewCourierFromCouriersPageTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    public function test_a_new_courier_account_returns_its_courier_profile(): void
    {
        $this->buildWorld();
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/v1/users', ['name' => 'Yao Nouveau', 'phone' => '0709090909', 'role' => 'courier'])
            ->assertCreated()->json('data.courier.id');

        $this->assertNotNull($id);
        $this->getJson("/api/v1/couriers/{$id}")->assertOk()->assertJsonPath('data.name', 'Yao Nouveau');
    }
}
