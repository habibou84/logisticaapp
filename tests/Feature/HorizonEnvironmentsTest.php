<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Horizon ne traite les files que si l'environnement a une configuration : sans elle, temps réel,
 * notifications et messages restent bloqués (cas vécu en préproduction, APP_ENV=staging).
 */
class HorizonEnvironmentsTest extends TestCase
{
    public function test_every_environment_has_queue_workers(): void
    {
        $environments = config('horizon.environments');

        foreach (['production', 'staging', 'local'] as $env) {
            $plan = collect($environments)->first(fn ($_, $name) => Str::is($name, $env));
            $this->assertNotEmpty($plan, "Aucun processus Horizon pour l'environnement « {$env} »");
            $this->assertGreaterThan(0, collect($plan)->max('maxProcesses'));
        }
    }
}
