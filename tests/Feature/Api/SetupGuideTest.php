<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\CashCollection;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Parcours de mise en route : étapes vérifiées sur les vraies données, essentielles et conseillées, « plus tard »
 * réservé aux étapes conseillées, page réservée aux administrateurs.
 */
class SetupGuideTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function step(string $key): array
    {
        return collect($this->getJson('/api/v1/setup')->assertOk()->json('data.steps'))->firstWhere('key', $key);
    }

    public function test_steps_follow_the_real_data(): void
    {
        Sanctum::actingAs($this->admin);

        // Tarifs : Cocody et Yopougon ont leurs prix (dans les deux sens), Plateau aucun
        $pricing = $this->step('pricing');
        $this->assertSame('partial', $pricing['status']);
        $this->assertStringContainsString('6 trajet(s) sans prix sur 9', $pricing['lines'][0]);
        $this->rule($this->grid, $this->plateau, $this->plateau, 800);
        $this->rule($this->grid, $this->plateau, $this->cocody, 1200);
        $this->rule($this->grid, $this->plateau, $this->yopougon, 1500);
        $this->rule($this->grid, $this->yopougon, $this->yopougon, 1000);
        $this->assertSame('done', $this->step('pricing')['status']);

        // Livreurs : créés, mais aucun ne s'est encore connecté ni n'a de zone
        $this->assertSame('partial', $this->step('couriers')['status']);
        $this->courierA->zones()->sync([$this->cocody->id]);
        $this->courierA->forceFill(['last_seen_at' => now()])->save();
        $this->assertSame('done', $this->step('couriers')['status']);

        // Une première course livrée
        $this->assertSame('todo', $this->step('first_delivery')['status']);
        $this->createOrder()->forceFill(['status' => OrderStatus::Delivered])->save();
        $this->assertSame('done', $this->step('first_delivery')['status']);

        // Sans zones : à faire
        Zone::query()->update(['is_active' => false]);
        $this->assertSame('todo', $this->step('zones')['status']);

        $data = $this->getJson('/api/v1/setup')->json('data');
        $this->assertFalse($data['ready']);
        $this->assertSame(8, $data['essentials']['total']);
    }

    public function test_only_recommended_steps_can_wait_and_admins_only(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/setup/skip', ['step' => 'messages', 'skipped' => true])->assertOk()
            ->assertJsonPath('data.steps.8.key', 'messages')->assertJsonPath('data.steps.8.skipped', true);
        $this->postJson('/api/v1/setup/skip', ['step' => 'pricing', 'skipped' => true])->assertJsonValidationErrors('step');
        $this->postJson('/api/v1/setup/skip', ['step' => 'messages', 'skipped' => false])->assertOk()->assertJsonPath('data.steps.8.skipped', false);

        // Ouverture à la première connexion : une seule fois par administrateur
        $this->getJson('/api/v1/setup')->assertJsonPath('data.seen', false);
        $this->postJson('/api/v1/setup/seen')->assertNoContent();
        $this->getJson('/api/v1/setup')->assertJsonPath('data.seen', true);

        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/setup')->assertForbidden();
    }

    public function test_the_test_course_is_followed_then_erased(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/setup/test-order')->assertCreated()->json('data.order_id');
        $this->assertSame($id, $this->postJson('/api/v1/setup/test-order')->json('data.order_id'), 'une seule course d\'essai à la fois');

        $order = Order::findOrFail($id);
        $this->assertTrue($order->is_test);
        $this->assertSame($this->admin->phone, $order->recipient_phone);
        $this->assertSame('Boutique d\'essai', $order->merchant->business_name);
        // La boutique d'essai ne compte pas pour l'étape des e-commerçants
        $this->assertStringContainsString('1 e-commerçant(s) actif(s)', $this->step('merchants')['lines'][0]);

        $step = $this->step('first_delivery');
        $this->assertSame('partial', $step['status']);
        $this->assertTrue($step['test']['checkpoints'][0]['done']);
        $this->assertFalse($step['test']['checkpoints'][5]['done']);

        // Livrée, argent encaissé par le livreur
        $order->forceFill(['status' => OrderStatus::Delivered, 'delivery_courier_id' => $this->courierA->id, 'delivered_at' => now()])->save();
        $collection = CashCollection::create(['company_id' => $this->company->id, 'order_id' => $order->id, 'courier_id' => $this->courierA->id,
            'amount_expected' => 1000, 'amount_collected' => 1000, 'collected_at' => now()]);
        $this->assertSame('done', $this->step('first_delivery')['status']);

        // Argent déjà versé à la caisse : on ne peut plus l'effacer
        $remittance = DB::table('courier_remittances')->insertGetId(['company_id' => $this->company->id, 'courier_id' => $this->courierA->id,
            'amount_expected' => 1000, 'amount_received' => 1000, 'difference' => 0, 'received_at' => now()]);
        $collection->forceFill(['remittance_id' => $remittance])->save();
        $this->deleteJson('/api/v1/setup/test-order')->assertStatus(422);
        $collection->forceFill(['remittance_id' => null])->save();

        // Effacée avec son argent et sa boutique ; l'étape reste faite
        $this->deleteJson('/api/v1/setup/test-order')->assertOk()->assertJsonPath('data.steps.7.status', 'done');
        $this->assertNull(Order::withTrashed()->find($id));
        $this->assertSame(0, CashCollection::query()->where('order_id', $id)->count());
        $this->assertSame(0, Merchant::withTrashed()->where('is_test', true)->count());
        $this->assertNotNull($this->company->fresh()->setup_test_delivered_at);
    }

    public function test_the_health_check_and_the_console_view(): void
    {
        Sanctum::actingAs($this->admin);
        WhatsAppAccount::create(['company_id' => $this->company->id, 'owner_type' => 'company', 'owner_id' => $this->company->id,
            'provider' => 'meta', 'status' => 'connected', 'token_expires_at' => now()->addDays(5)]);
        $this->company->update(['auto_pickup' => Company::AUTO_ON, 'auto_delivery' => Company::AUTO_ON]);

        $health = collect($this->getJson('/api/v1/setup')->json('data.health'));
        $this->assertSame(['alert', 'alert', 'warning', 'warning'], $health->pluck('level')->all());
        $this->assertStringStartsWith('Tarifs : 6 trajet(s) sans prix', $health[0]['text']);
        $this->assertStringContainsString('L\'accès WhatsApp expire le', $health[1]['text']);
        $this->assertStringContainsString('2 livreur(s) sans zone', $health[2]['text']);
        $this->assertStringContainsString('aucune règle de ramassage', $health[3]['text']);

        // Console : progression et alertes de chaque entreprise
        Sanctum::actingAs(User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null]));
        $row = collect($this->getJson('/api/v1/console/companies')->assertOk()->json('data'))->firstWhere('id', $this->company->id);
        $this->assertSame([false, 2], [$row['setup']['ready'], $row['setup']['alerts']]);
        $this->getJson("/api/v1/console/companies/{$this->company->id}")->assertOk()->assertJsonCount(4, 'data.setup.health');
    }
}
