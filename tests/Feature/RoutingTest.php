<?php

namespace Tests\Feature;

use Tests\TestCase;

class RoutingTest extends TestCase
{
    public function test_spa_is_served_for_front_end_routes(): void
    {
        $this->withoutVite();

        $this->get('/')->assertOk()->assertSee('id="app"', false);
        $this->get('/admin/utilisateurs')->assertOk()->assertSee('id="app"', false);
    }

    public function test_unknown_api_routes_return_json_404_instead_of_the_spa(): void
    {
        $this->get('/api/inexistant')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Ressource introuvable.']);
    }
}
