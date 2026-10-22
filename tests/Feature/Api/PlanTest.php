<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\PlatformFeature;
use App\Enums\Role;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Abonnements, étape 6A : formules, modules, limite de livreurs, consommation du mois.
 */
class PlanTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null]);
    }

    private function plan(array $attributes = []): Plan
    {
        return Plan::create([
            'name' => 'Essentiel', 'price_monthly' => 25000, 'included_orders' => 2,
            'overage_per_order' => 30, 'max_couriers' => null, 'features' => [], ...$attributes,
        ]);
    }

    public function test_super_admin_manages_plans(): void
    {
        Sanctum::actingAs($this->root);

        $this->postJson('/api/v1/console/plans/suggested')->assertCreated()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.1.name', 'Pro')->assertJsonPath('meta.trial_days', 30);
        $this->postJson('/api/v1/console/plans/suggested')->assertStatus(422);

        $id = $this->postJson('/api/v1/console/plans', [
            'name' => 'Mini', 'price_monthly' => 10000, 'included_orders' => 200, 'overage_per_order' => 40,
            'max_couriers' => 2, 'features' => ['shop', 'shop'],
        ])->assertCreated()->assertJsonPath('data.features', ['shop'])->json('data.id');

        $this->postJson('/api/v1/console/plans', ['name' => 'Mini', 'price_monthly' => 1, 'features' => ['inconnu']])
            ->assertJsonValidationErrors(['name', 'features.0']);

        $this->patchJson("/api/v1/console/plans/{$id}", ['is_active' => false, 'max_couriers' => null])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.max_couriers', null);

        // Réservé au super administrateur
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/console/plans')->assertForbidden();
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['plan_id' => $id])->assertForbidden();
    }

    public function test_new_company_starts_a_free_trial_on_its_plan(): void
    {
        $plan = $this->plan();
        $retired = $this->plan(['name' => 'Ancienne', 'is_active' => false]);
        Sanctum::actingAs($this->root);

        $this->postJson('/api/v1/companies', ['name' => 'Éclair', 'plan_id' => $retired->id])->assertJsonValidationErrors('plan_id');

        $id = $this->postJson('/api/v1/companies', ['name' => 'Éclair', 'plan_id' => $plan->id])->assertCreated()->json('data.id');
        $company = Company::find($id);
        $this->assertSame(now()->addDays(30)->toDateString(), $company->trial_ends_at->toDateString());
        $this->assertTrue($company->onTrial());

        // Sans formule : pas d'essai (aucune limite)
        $other = $this->postJson('/api/v1/companies', ['name' => 'Rapide'])->json('data.id');
        $this->assertNull(Company::find($other)->trial_ends_at);

        // Le super administrateur change la formule et prolonge l'essai
        $this->patchJson("/api/v1/companies/{$id}", ['plan_id' => null, 'trial_ends_at' => '2030-01-31'])
            ->assertOk()->assertJsonPath('data.trial_ends_at', '2030-01-31');
        $this->assertNull(Company::find($id)->plan_id);
    }

    public function test_modules_follow_the_plan(): void
    {
        [, $key] = ApiKey::issue($this->merchant, 'Site', ['orders:read'], $this->merchantUser);
        $this->merchant->update(['shop_slug' => 'chic', 'shop_enabled' => true]);

        // Sans formule : tout est disponible
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.company.features', PlatformFeature::values());
        $this->getJson('/api/v1/api-keys?merchant_id='.$this->merchant->id)->assertOk();
        $this->withToken($key)->getJson('/api/public/v1/zones')->assertOk();
        $this->getJson('/api/v1/shops/chic')->assertOk();

        // Formule sans modules
        $this->company->update(['plan_id' => $this->plan()->id]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.company.features', []);
        $this->getJson('/api/v1/api-keys?merchant_id='.$this->merchant->id)->assertForbidden()
            ->assertJsonPath('feature', 'api')
            ->assertJsonPath('message', '« API et webhooks » n\'est pas inclus dans la formule de votre entreprise. Contactez-nous pour changer de formule.');
        $this->getJson('/api/v1/products')->assertForbidden();
        $this->getJson('/api/v1/stock/movements')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($key)->getJson('/api/public/v1/zones')->assertForbidden();
        $this->getJson('/api/v1/shops/chic')->assertNotFound();

        // Boutiques seulement : le catalogue (produits) est ouvert, pas le stock
        $this->company->plan->update(['features' => ['shop']]);
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/products')->assertOk();
        $this->getJson('/api/v1/shop')->assertOk();
        $this->getJson('/api/v1/stock/movements')->assertForbidden();
        $this->getJson('/api/v1/shops/chic')->assertOk();
    }

    public function test_active_couriers_are_limited_by_the_plan(): void
    {
        // Deux livreurs dans le monde de test
        $this->company->update(['plan_id' => $this->plan(['max_couriers' => 2])->id]);
        Sanctum::actingAs($this->admin);

        $courier = ['name' => 'Yao', 'phone' => '0709090909', 'password' => 'password', 'role' => 'courier'];
        $this->postJson('/api/v1/users', $courier)->assertJsonValidationErrors('role')
            ->assertJsonPath('errors.role.0', 'Votre formule « Essentiel » permet 2 livreur(s) actif(s) : suspendez un livreur ou passez à une formule supérieure.');

        // Les autres rôles ne comptent pas
        $this->postJson('/api/v1/users', [...$courier, 'role' => 'dispatcher'])->assertCreated();

        // Un livreur suspendu libère une place, mais ne revient pas si elle est reprise
        $koffi = $this->courierA->user;
        $this->patchJson("/api/v1/users/{$koffi->id}", ['status' => 'suspended'])->assertOk();
        $this->postJson('/api/v1/users', [...$courier, 'phone' => '0708080808'])->assertCreated();
        $this->patchJson("/api/v1/users/{$koffi->id}", ['status' => 'active'])->assertJsonValidationErrors('status');

        // Modifier un livreur déjà actif reste possible
        $this->patchJson("/api/v1/users/{$this->courierB->user_id}", ['name' => 'Awa K.'])->assertOk();
    }

    public function test_subscription_shows_the_month_usage(): void
    {
        $this->company->update(['plan_id' => $this->plan()->id]);
        foreach (range(1, 3) as $i) {
            $this->createOrder();
        }
        $this->createOrder()->forceFill(['status' => OrderStatus::Cancelled])->save();

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/subscription')->assertOk()->json('data');

        $this->assertSame('Essentiel', $data['plan']['name']);
        $this->assertSame(['orders' => 3, 'included_orders' => 2, 'overage_orders' => 1, 'couriers' => 2, 'max_couriers' => null], $data['usage']);
        $this->assertSame(['base' => 25000, 'overage' => 30, 'total' => 25030], $data['estimate']);
        $this->assertFalse(collect($data['features'])->firstWhere('key', 'api')['included']);

        // Pendant l'essai : rien à payer
        $this->company->update(['trial_ends_at' => now()->addDays(5)->toDateString()]);
        Sanctum::actingAs($this->admin->fresh());
        $this->assertSame(0, $this->getJson('/api/v1/subscription')->json('data.estimate.total'));

        // Page réservée aux administrateurs ; la console voit la même chose
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/subscription')->assertForbidden();
        Sanctum::actingAs($this->root);
        $this->getJson("/api/v1/console/companies/{$this->company->id}")->assertJsonPath('data.subscription.usage.orders', 3)
            ->assertJsonPath('data.plan.name', 'Essentiel');
    }
}
