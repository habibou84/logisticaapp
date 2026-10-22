<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Applications Android et iOS : API appelée depuis l'application.
 */
class NativeAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_apps_may_call_the_company_api(): void
    {
        foreach (['https://localhost', 'capacitor://localhost'] as $origin) {
            $this->call('OPTIONS', '/api/v1/auth/login', server: [
                'HTTP_ORIGIN' => $origin,
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
            ])->assertHeader('Access-Control-Allow-Origin', $origin);
        }

        // Une autre origine n'est pas autorisée
        $this->call('OPTIONS', '/api/v1/auth/login', server: [
            'HTTP_ORIGIN' => 'https://exemple.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
