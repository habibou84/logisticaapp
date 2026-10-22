<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Mise en service de la plateforme : Cloudflare, limites par entreprise, adresses.
 */
class PlatformServerTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.domain' => 'jibiat.test']);
        $this->buildWorld();
        $this->company->update(['slug' => 'rapide']);
    }

    public function test_visitor_address_is_read_from_cloudflare_only(): void
    {
        Route::get('/api/_ip', fn (Request $request) => $request->ip().' '.$request->getScheme());
        $headers = ['X-Forwarded-For' => '41.66.1.2', 'X-Forwarded-Proto' => 'https'];

        // Sans mandataire de confiance : l'adresse de Cloudflare
        config(['trustedproxy.proxies' => null]);
        $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.9'])->get('http://rapide.jibiat.test/api/_ip', $headers)
            ->assertSeeText('173.245.48.9 http');

        config(['trustedproxy.proxies' => $this->trustedProxiesFor('cloudflare')]);

        // Derrière Cloudflare : adresse réelle du visiteur et HTTPS
        $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.9'])->get('http://rapide.jibiat.test/api/_ip', $headers)
            ->assertSeeText('41.66.1.2 https');
        // Un inconnu ne peut pas se faire passer pour quelqu'un d'autre
        $this->withServerVariables(['REMOTE_ADDR' => '5.6.7.8'])->get('http://rapide.jibiat.test/api/_ip', $headers)
            ->assertSeeText('5.6.7.8 http');
    }

    public function test_one_busy_company_does_not_use_up_the_others(): void
    {
        config(['platform.limits.user_per_minute' => 100, 'platform.limits.company_per_minute' => 3]);
        $other = $this->secondTenant();

        Sanctum::actingAs($this->admin);
        $this->getJson('http://rapide.jibiat.test/api/v1/auth/me')->assertOk();
        $this->getJson('http://rapide.jibiat.test/api/v1/auth/me')->assertOk();
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('http://rapide.jibiat.test/api/v1/auth/me')->assertOk();
        // Quota de l'entreprise atteint, pour tous ses comptes
        $this->getJson('http://rapide.jibiat.test/api/v1/auth/me')->assertStatus(429);

        // Les autres entreprises et la console continuent normalement
        Sanctum::actingAs($other);
        $this->getJson('http://eclair.jibiat.test/api/v1/auth/me')->assertOk();
        Sanctum::actingAs(User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null]));
        foreach (range(1, 4) as $i) {
            $this->getJson('http://admin.jibiat.test/api/v1/auth/me')->assertOk();
        }
    }

    public function test_one_account_cannot_use_up_its_company(): void
    {
        config(['platform.limits.user_per_minute' => 2, 'platform.limits.company_per_minute' => 100]);

        Sanctum::actingAs($this->admin);
        $this->getJson('http://rapide.jibiat.test/api/v1/auth/me')->assertOk();
        $this->getJson('http://rapide.jibiat.test/api/v1/auth/me')->assertOk();
        $this->getJson('http://rapide.jibiat.test/api/v1/auth/me')->assertStatus(429);

        Sanctum::actingAs($this->dispatcher);
        $this->getJson('http://rapide.jibiat.test/api/v1/auth/me')->assertOk();
    }

    public function test_status_command_lists_addresses_and_renames(): void
    {
        $this->artisan('platform:status')
            ->expectsOutputToContain('Console      : https://admin.jibiat.test')
            ->expectsOutputToContain('Aucun : créez-en un')
            ->assertFailed();

        User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null]);
        $this->artisan('platform:status')->expectsOutputToContain('Tout est prêt.')->assertSuccessful();

        $this->artisan('platform:status --rename=rapide:admin')->expectsOutputToContain('nouvelle adresse')->assertFailed();
        $this->artisan('platform:status --rename=inconnue:express')->expectsOutputToContain('Aucune entreprise')->assertFailed();
        $this->artisan('platform:status --rename=rapide:express')->expectsOutputToContain('remplacée par « express »')->assertSuccessful();
        $this->assertSame('https://express.jibiat.test', $this->company->fresh()->url());
    }

    /**
     * Réglage TRUSTED_PROXIES lu par config/trustedproxy.php, quelle que soit la valeur du .env.
     */
    private function trustedProxiesFor(string $value): mixed
    {
        $saved = [$_SERVER['TRUSTED_PROXIES'] ?? null, $_ENV['TRUSTED_PROXIES'] ?? null, getenv('TRUSTED_PROXIES')];
        $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = $value;
        putenv("TRUSTED_PROXIES={$value}");

        try {
            return (require base_path('config/trustedproxy.php'))['proxies'];
        } finally {
            [$server, $env, $put] = $saved;
            if ($server === null) {
                unset($_SERVER['TRUSTED_PROXIES']);
            } else {
                $_SERVER['TRUSTED_PROXIES'] = $server;
            }
            if ($env === null) {
                unset($_ENV['TRUSTED_PROXIES']);
            } else {
                $_ENV['TRUSTED_PROXIES'] = $env;
            }
            putenv($put === false ? 'TRUSTED_PROXIES' : "TRUSTED_PROXIES={$put}");
        }
    }

    private function secondTenant(): User
    {
        $company = Company::factory()->create(['slug' => 'eclair']);

        return User::factory()->withRole(Role::Admin)->create(['company_id' => $company->id]);
    }
}
