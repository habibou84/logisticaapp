<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\CashCollection;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\CompanyProvisioner;
use Database\Seeders\IncidentReasonsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Données fictives de la préproduction : un historique cohérent, sans aucun message envoyé, jamais en production.
 */
class PreprodDataTest extends TestCase
{
    use RefreshDatabase;

    private function installedCompany(): Company
    {
        $this->seed([RolesAndPermissionsSeeder::class, IncidentReasonsSeeder::class]);
        $company = Company::factory()->create(['name' => 'Logistica Delivery']);
        app(CompanyProvisioner::class)->provision($company);
        User::factory()->withRole(Role::Admin)->create(['company_id' => $company->id]);

        return $company;
    }

    public function test_it_fills_an_installed_company_with_a_consistent_history(): void
    {
        $company = $this->installedCompany();

        $this->artisan('app:preprod-data', ['--days' => 4, '--per-day' => 8, '--seed' => 7])->assertSuccessful();

        $this->assertSame(10, Merchant::forCompany($company->id)->count());
        $this->assertSame(8, Courier::forCompany($company->id)->count());
        $this->assertTrue(User::where('phone', '+2250799000101')->sole()->hasRole(Role::Courier->value));

        $orders = Order::forCompany($company->id)->get();
        $this->assertGreaterThan(15, $orders->count());
        $this->assertTrue($orders->every(fn (Order $o) => str_starts_with($o->merchant_reference, 'DEMO-') && $o->delivery_fee > 0));
        $this->assertTrue($orders->contains('status', OrderStatus::Delivered));

        // Encaissements des jours passés versés à la caisse
        $this->assertTrue(CashCollection::query()->whereNotNull('remittance_id')->exists());
        $this->assertFalse(CashCollection::query()->inCourierHands()->where('collected_at', '<', today())->exists());

        // Rien n'est parti : ni message, ni webhook, ni notification
        $this->assertSame(0, OutboundMessage::count());
        $this->assertSame(0, WebhookDelivery::count());
        $this->assertSame(0, DB::table('notifications')->count());

        // Relance : les jours déjà remplis sont sautés
        $this->artisan('app:preprod-data', ['--days' => 4, '--per-day' => 8])->assertSuccessful();
        $this->assertSame($orders->count(), Order::forCompany($company->id)->count());
    }

    public function test_it_refuses_production_and_needs_an_installed_company(): void
    {
        $this->artisan('app:preprod-data')->expectsOutputToContain('app:install')->assertFailed();

        $this->installedCompany();
        $this->app['env'] = 'production';
        $this->artisan('app:preprod-data')->expectsOutputToContain('Refusé')->assertFailed();
        $this->assertSame(0, Order::count());
    }
}
