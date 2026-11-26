<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_la_sonde_repond_quand_la_base_repond(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_la_sonde_est_en_erreur_quand_la_base_ne_repond_pas(): void
    {
        config([
            'database.connections.panne' => ['driver' => 'sqlite', 'database' => '/chemin/absent/base.sqlite'],
            'database.default' => 'panne',
            // Comme en production : la sonde répond « 500 » au lieu de relancer l'exception
            'app.debug' => false,
        ]);

        $this->get('/up')->assertStatus(500);
    }
}
