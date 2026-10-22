<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompanyScopeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = RolesAndPermissionsSeeder::class;

    private Company $companyA;

    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tenant_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('label');
        });

        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();

        TenantRecord::create(['company_id' => $this->companyA->id, 'label' => 'A']);
        TenantRecord::create(['company_id' => $this->companyB->id, 'label' => 'B']);
    }

    public function test_company_users_only_see_their_company_records(): void
    {
        Auth::login(User::factory()->for($this->companyA)->withRole(Role::Admin)->create());

        $this->assertSame(['A'], TenantRecord::pluck('label')->all());
        $this->assertNull(TenantRecord::where('label', 'B')->first());
    }

    public function test_super_admin_sees_every_record(): void
    {
        Auth::login(User::factory()->superAdmin()->create());

        $this->assertEqualsCanonicalizing(['A', 'B'], TenantRecord::pluck('label')->all());
    }

    public function test_company_id_is_filled_automatically_on_creation(): void
    {
        Auth::login(User::factory()->for($this->companyB)->withRole(Role::Dispatcher)->create());

        $record = TenantRecord::create(['label' => 'nouveau']);

        $this->assertSame($this->companyB->id, $record->company_id);
    }

    public function test_for_company_scope_targets_an_explicit_company(): void
    {
        Auth::login(User::factory()->for($this->companyA)->withRole(Role::Admin)->create());

        $this->assertSame(['B'], TenantRecord::forCompany($this->companyB->id)->pluck('label')->all());
    }
}

class TenantRecord extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = ['company_id', 'label'];
}
