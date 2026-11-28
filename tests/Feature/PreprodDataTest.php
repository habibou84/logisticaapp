<?php

namespace Tests\Feature;

use App\Enums\Activity;
use App\Enums\BillingFormula;
use App\Enums\ClientInvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\BillingPlan;
use App\Models\CashCollection;
use App\Models\ClientInvoice;
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

        $this->assertSame(14, Merchant::forCompany($company->id)->count());
        $this->assertSame(10, Courier::forCompany($company->id)->count());
        $this->assertTrue(User::where('phone', '+2250799000101')->sole()->hasRole(Role::Courier->value));

        $orders = Order::forCompany($company->id)->get();
        $this->assertGreaterThan(15, $orders->count());
        $this->assertTrue($orders->every(fn (Order $o) => str_starts_with($o->merchant_reference, 'DEMO-')));
        $this->assertTrue($orders->where('activity', Activity::Ecommerce)->every(fn (Order $o) => $o->delivery_fee > 0));
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

    public function test_distribution_and_mad_clients_have_their_contract_courses_and_month_invoices(): void
    {
        $company = $this->installedCompany();
        // Historique à cheval sur octobre : sa facture du mois est préparée
        $this->travelTo('2026-11-04 18:00:00');

        $this->artisan('app:preprod-data', ['--days' => 8, '--per-day' => 9, '--seed' => 11])
            ->doesntExpectOutputToContain('courses en erreur')->assertSuccessful();

        $orders = Order::forCompany($company->id)->get();
        foreach ([Activity::Distribution, Activity::Mad] as $activity) {
            $billed = $orders->where('activity', $activity);
            $this->assertGreaterThan(5, $billed->count(), $activity->value);
            // Facturées au mois : ni frais sur la course, ni argent à encaisser
            $this->assertTrue($billed->every(fn (Order $o) => $o->billing_plan_id !== null && $o->delivery_fee === 0 && (int) $o->cod_amount === 0));
            $this->assertTrue($billed->contains('status', OrderStatus::Delivered));
        }

        // MAD : chaque course faite par le livreur dédié du contrat, qui ne travaille pas pour les autres clients
        $plans = BillingPlan::forCompany($company->id)->with('couriers')->get();
        $this->assertSame(['distribution_contract', 'distribution_unit', 'mad_contract', 'mad_contract'], $plans->pluck('formula')->map->value->sort()->values()->all());
        $mad = $plans->where('formula', BillingFormula::MadContract)->flatMap->couriers->pluck('id');
        $this->assertCount(2, $mad);
        $this->assertTrue($orders->where('activity', Activity::Mad)->whereNotNull('delivery_courier_id')->every(fn (Order $o) => $mad->contains($o->delivery_courier_id)));
        $this->assertFalse($orders->where('activity', '!=', Activity::Mad)->contains(fn (Order $o) => $mad->contains($o->delivery_courier_id) || $mad->contains($o->pickup_courier_id)));

        // Factures d'octobre en brouillon pour les clients livrés ce mois-là
        $this->assertGreaterThan(0, ClientInvoice::forCompany($company->id)->where('status', ClientInvoiceStatus::Draft)->count());

        // Relance d'une préproduction déjà remplie en e-commerce seulement : la distribution et la MAD s'ajoutent
        Order::forCompany($company->id)->whereIn('activity', ['distribution', 'mad'])->get()->each(fn (Order $o) => $o->forceDelete());
        $ecommerce = Order::forCompany($company->id)->count();
        $this->artisan('app:preprod-data', ['--days' => 8, '--per-day' => 9])->assertSuccessful();
        $this->assertSame($ecommerce, Order::forCompany($company->id)->where('activity', 'ecommerce')->count());
        $this->assertGreaterThan(10, Order::forCompany($company->id)->whereIn('activity', ['distribution', 'mad'])->count());
        $this->assertSame(0, OutboundMessage::count());
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

    public function test_it_does_not_rely_on_development_packages(): void
    {
        // Les images Docker (production, préproduction) sont construites sans les dépendances de développement :
        // ni Faker (fake()), ni les fabriques de modèles
        $source = file_get_contents(app_path('Console/Commands/PreprodData.php'));

        // fake()->… (Faker), new Faker\…, Modèle::factory(…) ; Notification::fake() reste permis (Laravel)
        $this->assertDoesNotMatchRegularExpression('/(?<![\w:>$])fake\(\)\s*->|new\s+\\\\?Faker|::factory\(/', $source);
    }

    public function test_it_refuses_production_and_needs_an_installed_company(): void
    {
        $this->artisan('app:preprod-data')->expectsOutputToContain('app:install')->assertFailed();

        $this->installedCompany();
        $this->app['env'] = 'production';
        $this->artisan('app:preprod-data')->expectsOutputToContain('Refusé : APP_ENV=production')->assertFailed();

        // Tout environnement inconnu est refusé, pas seulement « production »
        $this->app['env'] = 'prod';
        $this->artisan('app:preprod-data')->expectsOutputToContain('Refusé : APP_ENV=prod')->assertFailed();

        // Adresse de la production, même avec un APP_ENV de préproduction (.env mal réglé)
        $this->app['env'] = 'staging';
        config(['app.url' => 'https://app.logisticadelivery.com', 'platform.preprod_access_code' => 'code']);
        $this->artisan('app:preprod-data')->expectsOutputToContain('adresse de la production')->assertFailed();

        // Préproduction sans son code d'accès : pas reconnue
        config(['app.url' => 'https://preprod.logisticadelivery.com', 'platform.preprod_access_code' => '']);
        $this->artisan('app:preprod-data')->expectsOutputToContain('PREPROD_ACCESS_CODE vide')->assertFailed();

        $this->assertSame(0, Order::count());
        $this->assertSame(0, User::where('phone', 'like', '+2250799%')->count());
    }
}
