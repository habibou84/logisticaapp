<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\User;
use App\Services\Legacy\SqlDumpReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Reprise des comptes de l'ancienne application (Laravel 5.8) depuis un export phpMyAdmin :
 * e-commerçants et livreurs gardent leur numéro et leur mot de passe.
 */
class LegacyImportTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->zone('Yopougon Siporex', $this->yopougon);
        $this->file = base_path('tests/fixtures/legacy-accounts.sql');
    }

    private function import(array $options = [])
    {
        return $this->artisan('legacy:import', ['company' => $this->company->slug, 'file' => $this->file, ...$options]);
    }

    public function test_reader_handles_phpmyadmin_exports(): void
    {
        $dump = new SqlDumpReader(<<<'SQL'
            CREATE TABLE `t` (
              `id` int(11) NOT NULL,
              `nom` varchar(191) DEFAULT NULL,
              `prix` decimal(8,2) DEFAULT NULL
            ) ENGINE=MyISAM;
            INSERT INTO `t` (`id`, `nom`, `prix`) VALUES
            (1, 'L\'ami ''du'' quartier, (Cocody)', -12.50),
            (2, NULL, 0x41);
            INSERT INTO `t` VALUES (3, 'ligne\nsuivante; fin', NULL);
            SQL);

        $this->assertSame([
            ['id' => '1', 'nom' => "L'ami 'du' quartier, (Cocody)", 'prix' => '-12.50'],
            ['id' => '2', 'nom' => null, 'prix' => 'A'],
            ['id' => '3', 'nom' => "ligne\nsuivante; fin", 'prix' => null],
        ], iterator_to_array($dump->rows('t'), false));
        $this->assertFalse($dump->hasTable('absente'));
    }

    public function test_merchants_and_couriers_keep_their_phone_and_password(): void
    {
        $this->import()->assertSuccessful();

        // E-commerçant avec son compte : zone reconnue, position, e-mail, mot de passe d'origine
        $chic = Merchant::where('business_name', 'Boutique Chic')->sole();
        $this->assertSame('+2250711223344', $chic->phone);
        $this->assertSame($this->cocody->id, $chic->pickup_zone_id);
        $this->assertSame('Riviera 3, près de la pharmacie', $chic->pickup_address);
        $this->assertEqualsWithDelta(5.3601, $chic->pickup_lat, 0.0001);
        $this->assertSame('import', $chic->source);
        $this->assertSame('2023-03-05', $chic->created_at->toDateString());
        $this->assertSame('Marchandises : Vêtements', $chic->notes);

        $owner = User::where('phone', '+2250711223344')->sole();
        $this->assertSame($chic->id, $owner->merchant_id);
        $this->assertSame('mariam@boutique.ci', $owner->email);
        $this->assertTrue($owner->hasRole(Role::MerchantOwner->value));
        $this->assertTrue(Hash::check('secret123', $owner->password));

        // Commune inconnue : adresse complétée, zone à choisir ; mot de passe non repris
        $jean = Merchant::where('phone', '+2250511223344')->sole();
        $this->assertSame("L'Atelier de Jean", $jean->business_name);
        $this->assertNull($jean->pickup_zone_id);
        $this->assertSame('Route de Bingerville, Bingerville', $jean->pickup_address);
        $this->assertNull($jean->pickup_lat);
        $this->assertNull(User::where('phone', '+2250511223344')->value('email'));

        // Fiche sans compte : e-commerçant créé, zone par la commune
        $this->assertSame($this->yopougon->id, Merchant::where('phone', '+2250102030405')->value('pickup_zone_id'));
        $this->assertSame(1, User::where('phone', '+2250102030405')->count());

        // Livreurs : actif avec son mot de passe ($2a$), inactif suspendu, fiche de livreur créée
        $adamo = User::where('phone', '+2250153141666')->sole();
        $this->assertTrue($adamo->isCourier());
        $this->assertSame(UserStatus::Active, $adamo->status);
        $this->assertTrue(Hash::check('awa-pass', $adamo->password));
        $this->assertSame('Commune : Cocody · Adresse : abatta', $adamo->courier->notes);
        $this->assertFalse($adamo->courier->is_available);

        $aya = User::where('phone', '+2250202020202')->sole();
        $this->assertSame(UserStatus::Suspended, $aya->status);
        $this->assertSame('Commune : Marcory', $aya->courier->notes);

        // Compte de l'équipe (ni « client » ni « livreur ») : non repris, à recréer
        $this->assertFalse(User::where('phone', '+2250700000099')->exists());
    }

    public function test_report_lists_skipped_rows_and_points_to_check(): void
    {
        $this->import()
            ->expectsTable(['', 'Nombre'], [
                ['E-commerçants importés', 3],
                ['Autres comptes des e-commerçants', 0],
                ['Livreurs importés', '2 (dont 1 suspendu(s))'],
                ['Lignes non importées', 2],
                ['Points à vérifier', 4],
                ['Comptes de l\'équipe non repris (à recréer)', 1],
            ])
            ->assertSuccessful();
    }

    public function test_dry_run_saves_nothing_and_import_can_be_rerun(): void
    {
        $merchants = Merchant::count();
        $users = User::count();

        $this->import(['--dry-run' => true])->expectsOutputToContain('rien n\'a été enregistré')->assertSuccessful();
        $this->assertSame([$merchants, $users], [Merchant::count(), User::count()]);

        $this->import()->assertSuccessful();
        $this->assertSame($merchants + 3, Merchant::count());
        $this->assertSame($users + 5, User::count());

        // Deuxième passage : tout est déjà là, aucun doublon
        $this->import()
            ->expectsTable(['', 'Nombre'], [
                ['E-commerçants importés', 0],
                ['Autres comptes des e-commerçants', 0],
                ['Livreurs importés', 0],
                ['Lignes non importées', 7],
                ['Points à vérifier', 0],
                ['Comptes de l\'équipe non repris (à recréer)', 1],
            ])
            ->assertSuccessful();
        $this->assertSame($merchants + 3, Merchant::count());
    }

    public function test_couriers_beyond_the_plan_are_imported_suspended(): void
    {
        // Deux livreurs actifs existent déjà ; la formule en permet deux
        $plan = Plan::create(['name' => 'Essentiel', 'price_monthly' => 25000, 'included_orders' => 100, 'overage_per_order' => 30, 'max_couriers' => 2, 'features' => []]);
        $this->company->update(['plan_id' => $plan->id]);

        $this->import()->assertSuccessful();

        $this->assertSame(UserStatus::Suspended, User::where('phone', '+2250153141666')->value('status'));
    }

    public function test_unknown_company_or_file_is_refused(): void
    {
        $this->artisan('legacy:import', ['company' => 'inconnue', 'file' => $this->file])->assertFailed();
        $this->artisan('legacy:import', ['company' => $this->company->slug, 'file' => '/tmp/absent.sql'])
            ->expectsOutputToContain('introuvable')
            ->assertFailed();
    }
}
