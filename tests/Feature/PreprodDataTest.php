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
use App\Models\PricingGrid;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\Zone;
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

    public function test_it_does_not_depend_on_the_company_tariffs_or_pickup_zones(): void
    {
        // Préproduction déjà réglée : grille standard avec un seul trajet, une commune où l'on ne ramasse pas
        $company = $this->installedCompany();
        $grid = PricingGrid::forCompany($company->id)->where('is_default', true)->sole();
        $zones = Zone::forCompany($company->id)->whereNull('parent_id')->get()->keyBy('name');
        PricingRule::create(['pricing_grid_id' => $grid->id, 'origin_zone_id' => $zones['Plateau']->id, 'destination_zone_id' => $zones['Plateau']->id, 'price' => 1000, 'is_symmetric' => true]);
        $zones['Cocody']->update(['is_pickup' => false]);

        $this->artisan('app:preprod-data', ['--days' => 2, '--per-day' => 6, '--seed' => 3])
            ->doesntExpectOutputToContain('courses en erreur')->assertSuccessful();

        $this->assertGreaterThan(5, Order::forCompany($company->id)->count());
        // Les tarifs de l'entreprise ne sont pas touchés : les clients fictifs ont leur grille
        $this->assertSame(1, PricingRule::where('pricing_grid_id', $grid->id)->count());
        $fictive = PricingGrid::forCompany($company->id)->where('name', 'Clients fictifs (préproduction)')->sole();
        $this->assertTrue(Merchant::forCompany($company->id)->get()->every(fn (Merchant $m) => $m->pricing_grid_id === $fictive->id && $m->pickup_zone_id !== $zones['Cocody']->id));
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
