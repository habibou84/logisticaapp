<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\Zone;
use App\Notifications\CompanySignedUp;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Inscription des entreprises en libre-service : adresse, formule, essai gratuit,
 * session ouverte sur l'adresse de l'entreprise, garde-fous.
 */
class CompanySignupTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = RolesAndPermissionsSeeder::class;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.domain' => 'jibiat.test', 'platform.trial_days' => 30]);
        $this->plan = Plan::create(['name' => 'Essentiel', 'price_monthly' => 25000, 'included_orders' => 500, 'overage_per_order' => 30, 'max_couriers' => 5, 'features' => ['stock'], 'is_active' => true, 'position' => 1]);
        Plan::create(['name' => 'Ancienne', 'price_monthly' => 1000, 'features' => [], 'is_active' => false, 'position' => 2]);
    }

    private function url(string $path, string $host = 'admin.jibiat.test'): string
    {
        return "http://{$host}/api/v1{$path}";
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return [
            'name' => 'Rapide Express', 'slug' => 'rapide', 'plan_id' => $this->plan->id,
            'admin_name' => 'Awa Koné', 'phone' => '07 01 02 03 04', 'email' => 'Awa@Rapide.ci',
            'password' => 'secret123', 'password_confirmation' => 'secret123', 'accept_terms' => true,
            ...$extra,
        ];
    }

    public function test_options_list_active_plans_on_the_console_host_only(): void
    {
        $this->getJson($this->url('/company-signup'))->assertOk()
            ->assertJsonPath('data.open', true)
            ->assertJsonPath('data.domain', 'jibiat.test')
            ->assertJsonPath('data.trial_days', 30)
            ->assertJsonCount(1, 'data.plans')
            ->assertJsonPath('data.plans.0.name', 'Essentiel');

        // Pas depuis la présentation, ni depuis l'adresse d'une entreprise
        $this->getJson($this->url('/company-signup', 'jibiat.test'))->assertNotFound();
        Company::factory()->create(['slug' => 'eclair']);
        $this->getJson($this->url('/company-signup', 'eclair.jibiat.test'))->assertNotFound();
    }

    public function test_slug_availability(): void
    {
        Company::factory()->create(['slug' => 'eclair']);

        $this->getJson($this->url('/company-signup/slug?slug=Rapide'))->assertOk()
            ->assertJsonPath('data', ['slug' => 'rapide', 'available' => true, 'message' => null, 'url' => 'https://rapide.jibiat.test']);
        $this->getJson($this->url('/company-signup/slug?slug=eclair'))->assertJsonPath('data.available', false)->assertJsonPath('data.message', 'Cette adresse est déjà prise.');
        $this->getJson($this->url('/company-signup/slug?slug=admin'))->assertJsonPath('data.message', 'Cette adresse est réservée.');
        $this->getJson($this->url('/company-signup/slug?slug=-mal-'))->assertJsonPath('data.available', false);
    }

    public function test_signup_creates_a_provisioned_company_on_trial_and_opens_the_admin_session(): void
    {
        Notification::fake();
        $root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null]);

        $data = $this->postJson($this->url('/company-signup'), $this->payload())->assertCreated()
            ->assertJsonPath('data.url', 'https://rapide.jibiat.test')
            ->assertJsonPath('data.company.trial_ends_at', now()->addDays(30)->toDateString())
            ->json('data');

        $company = Company::where('slug', 'rapide')->firstOrFail();
        $this->assertSame(['Rapide Express', $this->plan->id, 'signup', '+2250701020304', 'awa@rapide.ci'], [$company->name, $company->plan_id, $company->source, $company->phone, $company->email]);
        $this->assertTrue($company->onTrial());
        // Espace prêt : communes, grille tarifaire, plan de paie
        $this->assertSame(13, Zone::withoutGlobalScopes()->where('company_id', $company->id)->count());

        $admin = User::where('company_id', $company->id)->firstOrFail();
        $this->assertTrue($admin->hasRole(Role::Admin->value));
        $this->assertSame('Awa Koné', $admin->name);

        // Session ouverte sur l'adresse de l'entreprise (jeton dans le fragment)
        $this->assertStringStartsWith('https://rapide.jibiat.test/admin#session=', $data['login_url']);
        $token = substr($data['login_url'], strlen('https://rapide.jibiat.test/admin#session='));
        $this->withToken($token)->getJson($this->url('/auth/me', 'rapide.jibiat.test'))->assertOk()
            ->assertJsonPath('data.company.trial_ends_at', $company->trial_ends_at->toDateString());

        Notification::assertSentTo($root, CompanySignedUp::class, fn ($n) => $n->toArray($root)['href'] === "/console/entreprises/{$company->id}");

        // La console la signale comme inscrite en ligne
        Sanctum::actingAs($root);
        $this->getJson($this->url('/console/companies?source=signup'))->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source', 'signup')
            ->assertJsonPath('meta.totals.signups_month', 1);
    }

    public function test_validation_and_abuse_guards(): void
    {
        // Limite par minute testée à part : ici, les règles de l'inscription
        $this->withoutMiddleware(ThrottleRequests::class);
        Company::factory()->create(['slug' => 'eclair']);

        $this->postJson($this->url('/company-signup'), $this->payload(['slug' => 'eclair']))->assertJsonValidationErrors('slug');
        $this->postJson($this->url('/company-signup'), $this->payload(['plan_id' => Plan::where('is_active', false)->value('id')]))->assertJsonValidationErrors('plan_id');
        $this->postJson($this->url('/company-signup'), $this->payload(['accept_terms' => false]))->assertJsonValidationErrors('accept_terms');
        $this->postJson($this->url('/company-signup'), $this->payload(['password_confirmation' => 'autre']))->assertJsonValidationErrors('password');
        // Robot : champ invisible rempli
        $this->postJson($this->url('/company-signup'), $this->payload(['website' => 'http://spam']))->assertUnprocessable();
        $this->assertSame(1, Company::count());

        // Un seul essai par numéro
        $this->postJson($this->url('/company-signup'), $this->payload())->assertCreated();
        $this->postJson($this->url('/company-signup'), $this->payload(['slug' => 'rapide-2', 'phone' => '+225 0701020304']))
            ->assertUnprocessable()->assertJsonPath('errors.phone.0', fn ($m) => str_contains($m, 'https://rapide.jibiat.test'));

        // Trois entreprises par jour depuis une même connexion
        $this->postJson($this->url('/company-signup'), $this->payload(['slug' => 'deux', 'phone' => '0701020305']))->assertCreated();
        $this->postJson($this->url('/company-signup'), $this->payload(['slug' => 'trois', 'phone' => '0701020306']))->assertCreated();
        $this->postJson($this->url('/company-signup'), $this->payload(['slug' => 'quatre', 'phone' => '0701020307']))->assertUnprocessable();
        $this->assertSame(4, Company::count());
    }

    public function test_console_can_close_signups(): void
    {
        $root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null]);
        Sanctum::actingAs($root);
        $this->getJson($this->url('/console/settings'))->assertJsonPath('data.company_signup', true);
        $this->putJson($this->url('/console/settings'), ['company_signup' => false])->assertOk()->assertJsonPath('data.company_signup', false);
        $this->assertSame('0', PlatformSetting::get('company_signup'));

        $this->getJson($this->url('/company-signup'))->assertJsonPath('data.open', false)->assertJsonPath('data.plans', []);
        $this->postJson($this->url('/company-signup'), $this->payload())->assertUnprocessable();

        // Sans formule proposée : fermé aussi
        PlatformSetting::put('company_signup', '1');
        Plan::query()->update(['is_active' => false]);
        $this->getJson($this->url('/company-signup'))->assertJsonPath('data.open', false);
        $this->assertSame(0, Company::count());
    }

    public function test_signups_are_rate_limited_per_connection(): void
    {
        foreach (range(1, 5) as $i) {
            $this->postJson($this->url('/company-signup'), $this->payload(['slug' => '-'.$i]))->assertUnprocessable();
        }
        $this->postJson($this->url('/company-signup'), $this->payload())->assertTooManyRequests();
    }
}
