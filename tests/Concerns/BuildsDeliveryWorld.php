<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PayPlan;
use App\Models\PricingGrid;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\Zone;
use App\Services\Orders\OrderService;
use Database\Seeders\IncidentReasonsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Monde de test : une entreprise avec zones (Cocody, Yopougon, Plateau, Angré),
 * une grille par défaut (Cocody->Cocody 1000, Cocody<->Yopougon 1500), un marchand
 * et son gérant, un admin, un dispatcher et deux livreurs.
 */
trait BuildsDeliveryWorld
{
    protected Company $company;

    protected Zone $cocody;

    protected Zone $yopougon;

    protected Zone $plateau;

    protected Zone $angre;

    protected PricingGrid $grid;

    protected Merchant $merchant;

    protected User $merchantUser;

    protected User $admin;

    protected User $dispatcher;

    protected Courier $courierA;

    protected Courier $courierB;

    protected function buildWorld(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, IncidentReasonsSeeder::class]);

        $this->company = Company::factory()->create();
        $this->cocody = $this->zone('Cocody');
        $this->yopougon = $this->zone('Yopougon');
        $this->plateau = $this->zone('Plateau');
        $this->angre = $this->zone('Angré', $this->cocody);

        $this->grid = PricingGrid::factory()->default()->create(['company_id' => $this->company->id]);
        $this->rule($this->grid, $this->cocody, $this->cocody, 1000);
        $this->rule($this->grid, $this->cocody, $this->yopougon, 1500);

        $this->merchant = Merchant::factory()->create([
            'company_id' => $this->company->id,
            'business_name' => 'Boutique Test',
            'pickup_zone_id' => $this->cocody->id,
            'pickup_address' => 'Riviera 2',
        ]);
        $this->merchantUser = $this->userWithRole(Role::MerchantOwner, ['merchant_id' => $this->merchant->id]);

        $this->admin = $this->userWithRole(Role::Admin);
        $this->dispatcher = $this->userWithRole(Role::Dispatcher);
        $this->courierA = $this->userWithRole(Role::Courier, ['name' => 'Koffi Ramasseur'])->courier;
        $this->courierB = $this->userWithRole(Role::Courier, ['name' => 'Awa Livreuse'])->courier;
    }

    protected function zone(string $name, ?Zone $parent = null, ?Company $company = null): Zone
    {
        return Zone::factory()->create([
            'company_id' => ($company ?? $this->company)->id,
            'parent_id' => $parent?->id,
            'name' => $name,
        ]);
    }

    protected function rule(PricingGrid $grid, Zone $from, Zone $to, int $price, bool $symmetric = true): PricingRule
    {
        return PricingRule::create([
            'pricing_grid_id' => $grid->id,
            'origin_zone_id' => $from->id,
            'destination_zone_id' => $to->id,
            'price' => $price,
            'is_symmetric' => $symmetric,
        ]);
    }

    /**
     * Plan de rémunération : personnel au livreur indiqué, sinon plan par défaut de l'entreprise.
     * $rules : [événement => montant fixe] ou liste de règles complètes.
     */
    protected function payPlan(array $rules, ?Courier $courier = null, array $attributes = []): PayPlan
    {
        $plan = PayPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'courier_id' => $courier?->id,
            'name' => $courier ? 'Plan personnel' : 'Plan standard',
            'is_default' => $courier === null,
            ...$attributes,
        ]);

        foreach ($rules as $event => $rule) {
            $plan->rules()->create(is_array($rule) ? $rule : ['event' => $event, 'calc' => 'fixed', 'amount' => $rule]);
        }

        $courier?->update(['pay_plan_id' => $plan->id]);

        return $plan->load('rules');
    }

    protected function userWithRole(Role $role, array $attributes = [], ?Company $company = null): User
    {
        return User::factory()->withRole($role)->create([
            'company_id' => ($company ?? $this->company)->id,
            ...$attributes,
        ])->fresh();
    }

    /**
     * Crée une course via le service (prix calculé, journal écrit).
     */
    protected function createOrder(array $data = [], ?User $actor = null, ?Merchant $merchant = null): Order
    {
        return app(OrderService::class)->create($actor ?? $this->merchantUser, $merchant ?? $this->merchant, [
            'recipient_name' => 'Jean Kouadio',
            'recipient_phone' => '0707070707',
            'delivery_zone_id' => $this->yopougon->id,
            'delivery_address' => 'Yopougon Siporex',
            'items_amount' => 10000,
            ...$data,
        ]);
    }
}
