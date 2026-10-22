<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\StorageBillingType;
use App\Enums\SurchargeType;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierLocation;
use App\Models\Hub;
use App\Models\Merchant;
use App\Models\PayPlan;
use App\Models\PricingGrid;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\StorageContract;
use App\Models\User;
use App\Models\Zone;
use App\Services\CompanyProvisioner;
use App\Services\Stock\StockKeeper;
use Illuminate\Database\Seeder;

/**
 * Données de démonstration (environnements local et de test uniquement).
 * Mot de passe de tous les comptes : "password".
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->user(null, Role::SuperAdmin, 'Super administrateur', '0700000000', 'superadmin@livraison.test');

        $company = Company::firstOrCreate(
            ['slug' => 'livraison-express-ci'],
            ['name' => 'Livraison Express CI', 'phone' => '+2252722000000', 'email' => 'contact@livraison.test'],
        );

        app(CompanyProvisioner::class)->provision($company);

        // Zones d'expédition : colis déposés à une gare routière pour l'intérieur du pays
        foreach (['Expédition Bouaké (gare UTB Adjamé)' => 3000, 'Expédition Yamoussoukro (gare UTB Adjamé)' => 2500] as $name => $estimate) {
            Zone::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $company->id, 'name' => $name, 'parent_id' => null],
                ['city' => 'Abidjan', 'is_shipping' => true, 'shipping_fee_estimate' => $estimate, 'sort_order' => 100],
            );
        }

        $this->pricing($company);

        $this->user($company, Role::Admin, 'Administrateur', '0700000001', 'admin@livraison.test');
        $this->user($company, Role::Dispatcher, 'Dispatcher', '0700000002', 'dispatch@livraison.test');
        $this->user($company, Role::Cashier, 'Caissier', '0700000003', 'caisse@livraison.test');
        $this->user($company, Role::Courier, 'Koffi Livreur', '0700000004', null);
        $this->user($company, Role::Courier, 'Awa Livreuse', '0700000005', null);

        // Rémunération à la course des livreurs de démonstration (plan par défaut)
        $plan = PayPlan::defaultFor($company->id)
            ?? PayPlan::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Plan standard', 'is_default' => true]);
        foreach (['pickup' => 300, 'delivery' => 500, 'failed_attempt' => 200, 'return' => 400] as $event => $amount) {
            $plan->rules()->create(['event' => $event, 'calc' => 'fixed', 'amount' => $amount]);
        }

        // Positions de démonstration pour la carte des livreurs (Cocody et Plateau)
        foreach (['+2250700000004' => [5.3598, -3.9875], '+2250700000005' => [5.3236, -4.0187]] as $phone => [$lat, $lng]) {
            Courier::withoutGlobalScopes()->whereNull('current_lat')
                ->whereHas('user', fn ($q) => $q->where('phone', $phone))
                ->update(['current_lat' => $lat, 'current_lng' => $lng, 'last_location_at' => now(), 'is_available' => true]);
        }

        $this->demoTrack($company);

        $cocody = Zone::forCompany($company->id)->where('name', 'Cocody')->first();
        $merchant = Merchant::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'phone' => '+2250500000001'],
            [
                'business_name' => 'Boutique Chic Abidjan',
                'contact_name' => 'Mariam Koné',
                'whatsapp_phone' => '0500000001',
                'pickup_zone_id' => $cocody->id,
                'pickup_address' => 'Riviera 2, rue des Jardins',
                'pickup_landmark' => 'Face pharmacie des Jardins',
                // Riviera 2 : position du ramassage sur la carte des livreurs
                'pickup_lat' => 5.3631,
                'pickup_lng' => -3.9735,
            ],
        );
        $this->user($company, Role::MerchantOwner, 'Mariam Koné', '0500000001', 'boutique@livraison.test', $merchant);
        $this->user($company, Role::HubAgent, 'Agent d\'entrepôt', '0700000006', 'entrepot@livraison.test');

        $this->stock($company, $merchant);
    }

    /**
     * Trajet de la matinée de Koffi (Cocody → Adjamé → Plateau) pour l'historique des trajets.
     */
    private function demoTrack(Company $company): void
    {
        $courier = Courier::withoutGlobalScopes()->whereHas('user', fn ($q) => $q->where('phone', '+2250700000004'))->first();

        if (! $courier || CourierLocation::withoutGlobalScopes()->where('courier_id', $courier->id)->whereDate('recorded_at', today())->exists()) {
            return;
        }

        $route = [
            [5.3598, -3.9875], [5.3562, -3.9921], [5.3531, -3.9978], [5.3502, -4.0040], [5.3489, -4.0102],
            [5.3497, -4.0161], [5.3512, -4.0214], [5.3466, -4.0233], [5.3398, -4.0221], [5.3330, -4.0203],
            [5.3271, -4.0192], [5.3236, -4.0187],
        ];
        $start = today()->setTime(8, 0);

        foreach ($route as $i => [$lat, $lng]) {
            CourierLocation::create([
                'company_id' => $company->id, 'courier_id' => $courier->id,
                'lat' => $lat, 'lng' => $lng, 'accuracy' => 15, 'recorded_at' => $start->copy()->addMinutes($i * 3),
            ]);
        }
    }

    /**
     * Un entrepôt, quelques produits de la boutique (à l'entrepôt et chez elle) et un contrat de stockage.
     */
    private function stock(Company $company, Merchant $merchant): void
    {
        $plateau = Zone::forCompany($company->id)->where('name', 'Plateau')->first()
            ?? Zone::forCompany($company->id)->whereNull('parent_id')->first();

        $hub = Hub::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Entrepôt Plateau'],
            ['zone_id' => $plateau->id, 'address' => 'Avenue Chardy, immeuble Alpha', 'phone' => '+2252720000006'],
        );

        if (Product::withoutGlobalScopes()->where('merchant_id', $merchant->id)->exists()) {
            return;
        }

        $keeper = app(StockKeeper::class);
        $warehouse = $keeper->location($merchant, $hub);
        $home = $keeper->location($merchant);

        foreach ([
            ['Robe wax taille M', 'ROBE-M', 15000, 5, 24, 0],
            ['Sac à main cuir', 'SAC-01', 25000, 3, 8, 2],
            ['Sandales dorées', 'SAND-38', 12000, 4, 3, 0],
            ['Foulard soie', 'FOUL-01', 7500, null, 0, 6],
        ] as [$name, $sku, $price, $threshold, $atHub, $atHome]) {
            $product = Product::create([
                'company_id' => $company->id, 'merchant_id' => $merchant->id,
                'name' => $name, 'sku' => $sku, 'price' => $price, 'low_stock_threshold' => $threshold,
            ]);

            if ($atHub > 0) {
                $keeper->receive(null, $product, $warehouse, $atHub, 'Dépôt initial');
            }
            if ($atHome > 0) {
                $keeper->receive(null, $product, $home, $atHome, 'Stock de départ');
            }
        }

        StorageContract::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'merchant_id' => $merchant->id],
            ['hub_id' => $hub->id, 'billing_type' => StorageBillingType::MonthlyFlat, 'price' => 10000, 'starts_on' => now()->startOfMonth()],
        );
    }

    /**
     * Grille par défaut : 1 000 F dans la même commune, 1 500 F entre communes,
     * 2 000 F vers ou depuis la périphérie (Anyama, Bingerville, Songon).
     */
    private function pricing(Company $company): void
    {
        $grid = PricingGrid::forCompany($company->id)->where('is_default', true)->first();
        $zones = Zone::forCompany($company->id)->whereNull('parent_id')->get();
        $outskirts = ['Anyama', 'Bingerville', 'Songon'];
        $shipping = $zones->where('is_shipping', true);
        $zones = $zones->where('is_shipping', false);

        // Course jusqu'à la gare d'Adjamé, depuis toute commune
        foreach ($shipping as $station) {
            foreach ($zones as $origin) {
                PricingRule::updateOrCreate(
                    ['pricing_grid_id' => $grid->id, 'origin_zone_id' => $origin->id, 'destination_zone_id' => $station->id],
                    ['price' => 1500, 'is_symmetric' => true],
                );
            }
        }

        foreach ($zones as $origin) {
            foreach ($zones as $destination) {
                if ($origin->id > $destination->id) {
                    continue; // règles symétriques : une seule par paire
                }

                $price = match (true) {
                    $origin->id === $destination->id => 1000,
                    in_array($origin->name, $outskirts) || in_array($destination->name, $outskirts) => 2000,
                    default => 1500,
                };

                PricingRule::updateOrCreate(
                    ['pricing_grid_id' => $grid->id, 'origin_zone_id' => $origin->id, 'destination_zone_id' => $destination->id],
                    ['price' => $price, 'is_symmetric' => true],
                );
            }
        }

        $grid->surcharges()->firstOrCreate(['type' => SurchargeType::Express], ['amount' => 1000]);
        $grid->surcharges()->firstOrCreate(['type' => SurchargeType::Weight, 'min_value' => 10], ['amount' => 1000]);
    }

    private function user(?Company $company, Role $role, string $name, string $phone, ?string $email, ?Merchant $merchant = null): void
    {
        $user = User::where('phone', '+225'.$phone)->first() ?? User::create([
            'company_id' => $company?->id,
            'merchant_id' => $merchant?->id,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'password' => 'password',
        ]);

        $user->syncRoles([$role->value]);
        $user->syncCourierProfile();
    }
}
