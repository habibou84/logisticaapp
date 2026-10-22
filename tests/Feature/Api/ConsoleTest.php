<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Company;
use App\Models\SupportSession;
use App\Models\User;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Console du super administrateur : entreprises, activité, création, assistance.
 */
class ConsoleTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.domain' => 'jibiat.test']);
        $this->buildWorld();
        $this->company->update(['slug' => 'rapide', 'name' => 'Rapide Express']);
        $this->root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null, 'name' => 'Habib Root']);
    }

    private function console(string $path): string
    {
        return 'http://admin.jibiat.test/api/v1'.$path;
    }

    public function test_super_admin_sees_every_company_with_its_activity(): void
    {
        app(OrderWorkflow::class)->transition($this->dispatcher, $this->createOrder(), OrderStatus::Confirmed);
        $this->createOrder();
        Company::factory()->create(['name' => 'Éclair', 'slug' => 'eclair', 'status' => 'suspended']);

        Sanctum::actingAs($this->root);
        $data = $this->getJson($this->console('/console/companies'))->assertOk()
            ->assertJsonPath('meta.domain', 'jibiat.test')
            ->assertJsonPath('meta.totals', ['companies' => 2, 'active' => 1, 'orders_month' => 2, 'on_trial' => 0, 'signups_month' => 0])
            ->json('data');

        $rapide = collect($data)->firstWhere('slug', 'rapide');
        $this->assertSame(['https://rapide.jibiat.test', 1, 2, 2], [$rapide['url'], $rapide['merchants_count'], $rapide['couriers_count'], $rapide['orders_month_count']]);
        $this->assertNotNull($rapide['last_order_at']);

        $this->getJson($this->console('/console/companies?status=suspended'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'eclair');
        $detail = $this->getJson($this->console("/console/companies/{$this->company->id}"))->assertOk()
            ->assertJsonPath('data.domain', 'jibiat.test')->json('data');
        // Équipe utilisable pour l'assistance : ni livreurs, ni marchands
        $this->assertEqualsCanonicalizing([$this->admin->id, $this->dispatcher->id], array_column($detail['staff'], 'id'));

        // Réservé au super administrateur
        Sanctum::actingAs($this->admin);
        $this->getJson('http://rapide.jibiat.test/api/v1/console/companies')->assertForbidden();
    }

    public function test_company_address_rules_on_creation(): void
    {
        Sanctum::actingAs($this->root);
        $payload = fn (array $extra) => ['name' => 'Nouvelle Livraison', 'admin' => ['name' => 'Gérant', 'phone' => '0701010101', 'password' => 'password'], ...$extra];

        $this->postJson($this->console('/companies'), $payload(['slug' => 'admin']))->assertJsonValidationErrors('slug');
        $this->postJson($this->console('/companies'), $payload(['slug' => 'Mon_Entreprise']))->assertJsonValidationErrors('slug');
        $this->postJson($this->console('/companies'), $payload(['slug' => 'rapide']))->assertJsonValidationErrors('slug');

        $company = $this->postJson($this->console('/companies'), $payload(['slug' => 'nouvelle', 'timezone' => null]))->assertCreated()->json('data');
        $this->assertSame('Africa/Abidjan', Company::find($company['id'])->timezone);

        // Sans adresse : proposée d'après le nom
        $this->assertSame('nouvelle-livraison', $this->postJson($this->console('/companies'), $payload([]))->assertCreated()->json('data.slug'));

        // L'entreprise ne change pas elle-même son adresse
        Sanctum::actingAs($this->admin);
        $this->patchJson("http://rapide.jibiat.test/api/v1/companies/{$this->company->id}", ['slug' => 'rapide2'])->assertForbidden();
    }

    public function test_support_session_opens_the_company_space_for_an_hour(): void
    {
        Sanctum::actingAs($this->root);
        $data = $this->postJson($this->console("/console/companies/{$this->company->id}/support-session"), ['reason' => 'Réglage des tarifs'])
            ->assertCreated()
            ->assertJsonPath('data.user.id', $this->admin->id)
            ->json('data');

        $this->assertStringStartsWith('https://rapide.jibiat.test/admin#support=', $data['url']);
        $token = substr($data['url'], strlen('https://rapide.jibiat.test/admin#support='));
        $this->assertDatabaseHas('support_sessions', ['company_id' => $this->company->id, 'opened_by' => $this->root->id, 'user_id' => $this->admin->id, 'reason' => 'Réglage des tarifs']);

        // Le jeton ouvre l'espace de l'entreprise, avec le bandeau d'assistance
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('http://rapide.jibiat.test/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.id', $this->admin->id)
            ->assertJsonPath('data.support_session.opened_by', 'Habib Root');

        // Une heure plus tard, il ne vaut plus rien
        $this->travel(SupportSession::MINUTES + 1)->minutes();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('http://rapide.jibiat.test/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_support_session_rules(): void
    {
        Sanctum::actingAs($this->root);
        $url = $this->console("/console/companies/{$this->company->id}/support-session");

        // Un membre précis de l'équipe ; ni livreur, ni marchand, ni compte d'une autre entreprise
        $this->postJson($url, ['user_id' => $this->dispatcher->id])->assertCreated()->assertJsonPath('data.user.id', $this->dispatcher->id);
        $this->postJson($url, ['user_id' => $this->courierA->user_id])->assertJsonValidationErrors('user_id');
        $this->postJson($url, ['user_id' => $this->merchantUser->id])->assertJsonValidationErrors('user_id');
        $stranger = User::factory()->withRole(Role::Admin)->create();
        $this->postJson($url, ['user_id' => $stranger->id])->assertJsonValidationErrors('user_id');

        $this->company->update(['status' => 'suspended']);
        $this->postJson($url)->assertJsonValidationErrors('company');

        // Hors console : les autres comptes n'y ont pas accès
        Sanctum::actingAs($this->admin);
        $this->postJson("http://rapide.jibiat.test/api/v1/console/companies/{$this->company->id}/support-session")->assertForbidden();
    }

    public function test_console_address_opens_the_companies_board(): void
    {
        $this->get('http://admin.jibiat.test/')->assertRedirect('/console');
        $this->withoutVite()->get('http://admin.jibiat.test/console')->assertOk();
    }
}
