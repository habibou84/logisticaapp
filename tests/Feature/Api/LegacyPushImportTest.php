<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Reprise par le bouton de l'ancienne application : clé créée dans Paramètres, comptes envoyés
 * par paquets (e-commerçants, livreurs), mêmes numéros et mêmes mots de passe. L'équipe se recrée.
 */
class LegacyPushImportTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    // Mot de passe « secret123 » haché par l'ancienne application (bcrypt)
    private const HASH = '$2y$10$l4EdonX1RMloN0UFYN1.Z.TGDzAlof1zNELfbYOhY6FO67hOU6tTu';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function key(): string
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson('/api/v1/company/legacy-import/key')->assertCreated()->json('data.key');
    }

    private function send(string $key, array $payload)
    {
        return $this->withToken($key)->postJson('/api/legacy-import', $payload);
    }

    private function user(int $id, string $phone, array $extra = []): array
    {
        return ['id' => $id, 'name' => "Compte {$id}", 'email' => null, 'phone' => $phone, 'usertype' => null, 'password' => self::HASH,
            'created_at' => '2024-01-01 10:00:00', 'livreur_id' => null, 'client_id' => null, ...$extra];
    }

    public function test_clients_couriers_and_staff_arrive_with_their_passwords(): void
    {
        $key = $this->key();

        // Paquet des e-commerçants : la boutique, son compte et un employé (users.client_id)
        $this->send($key, [
            'step' => 'clients',
            'clients' => [['id' => 5, 'nom' => 'Boutique Chic', 'phone' => '0711223344', 'city' => 'cocody', 'adresse' => 'Riviera 3',
                'goods_type' => null, 'user_id' => 10, 'latitude' => '5.36', 'longitude' => '-3.97', 'created_at' => '2023-03-05 09:00:00', 'updated_at' => null]],
            'users' => [
                $this->user(10, '07 11 22 33 44', ['usertype' => 'client', 'client_id' => 5, 'name' => 'Mariam']),
                $this->user(12, '0711223355', ['usertype' => 'client', 'client_id' => 5, 'name' => 'Vendeuse']),
            ],
        ])->assertOk()
            ->assertJsonPath('data.counts.merchants', 1)
            ->assertJsonPath('data.counts.merchant_users', 1);

        $chic = Merchant::where('phone', '+2250711223344')->sole();
        $this->assertSame($this->cocody->id, $chic->pickup_zone_id);
        $owner = User::where('phone', '+2250711223344')->sole();
        $this->assertTrue($owner->hasRole(Role::MerchantOwner->value));
        $this->assertTrue(Hash::check('secret123', $owner->password));
        $seller = User::where('phone', '+2250711223355')->sole();
        $this->assertSame($chic->id, $seller->merchant_id);
        $this->assertTrue($seller->hasRole(Role::MerchantStaff->value));

        // Paquet des livreurs
        $this->send($key, [
            'step' => 'livreurs',
            'livreurs' => [['id' => 20, 'nom' => 'Adamo', 'phone' => '0153141666', 'adresse' => 'abatta', 'city' => 'Cocody', 'status' => 'active', 'user_id' => 20]],
            'users' => [$this->user(20, '0153141666', ['usertype' => 'livreur', 'livreur_id' => 20])],
        ])->assertOk()->assertJsonPath('data.counts.couriers', 1);
        $adamo = User::where('phone', '+2250153141666')->sole();
        $this->assertTrue($adamo->isCourier());
        $this->assertTrue(Hash::check('secret123', $adamo->password));

        $this->assertNotNull($this->company->fresh()->legacy_import_used_at);
    }

    public function test_team_accounts_and_the_admin_client_are_not_imported(): void
    {
        $key = $this->key();

        // Fiche client de l'administrateur (compte « admin »), et compte de l'équipe rattaché à une boutique
        $this->send($key, [
            'step' => 'clients',
            'clients' => [
                ['id' => 1, 'nom' => 'Agence', 'phone' => '0700000099', 'city' => 'Cocody', 'adresse' => 'Siège', 'user_id' => 1],
                ['id' => 2, 'nom' => 'Boutique Zen', 'phone' => '0711000000', 'city' => 'Cocody', 'adresse' => 'Angré', 'user_id' => 4],
            ],
            'users' => [
                $this->user(1, '0700000099', ['usertype' => 'admin', 'client_id' => 1]),
                $this->user(4, '0711000000', ['usertype' => 'client', 'client_id' => 2]),
                $this->user(2, '0711000001', ['usertype' => 'operateur', 'client_id' => 2]),
            ],
        ])->assertOk()
            ->assertJsonPath('data.counts.merchants', 1)
            ->assertJsonPath('data.counts.merchant_users', 0)
            ->assertJsonPath('data.skipped.0.reason', 'rattaché à un compte de l\'équipe (type « admin ») : non repris');

        $this->assertFalse(Merchant::where('phone', '+2250700000099')->exists());
        $this->assertFalse(User::whereIn('phone', ['+2250700000099', '+2250711000001'])->exists());
        $this->assertTrue(Merchant::where('phone', '+2250711000000')->exists());

        // Livreur rattaché à un compte qui n'est pas « livreur »
        $this->send($key, [
            'step' => 'livreurs',
            'livreurs' => [['id' => 3, 'nom' => 'Chef', 'phone' => '0700000088', 'status' => 'active', 'user_id' => 3]],
            'users' => [$this->user(3, '0700000088', ['usertype' => 'superviseur', 'livreur_id' => 3])],
        ])->assertOk()->assertJsonPath('data.counts.couriers', 0)->assertJsonCount(1, 'data.skipped');

        // Plus d'étape « équipe »
        $this->send($key, ['step' => 'staff', 'users' => []])->assertUnprocessable();

    }

    public function test_simulation_saves_nothing_and_sending_twice_creates_no_duplicate(): void
    {
        $key = $this->key();
        $payload = [
            'step' => 'livreurs',
            'livreurs' => [['id' => 29, 'nom' => 'Aya', 'phone' => '0202020202', 'status' => 'inactive', 'user_id' => null]],
            'users' => [],
        ];

        $this->send($key, [...$payload, 'dry_run' => true])->assertOk()->assertJsonPath('data.counts.couriers', 1);
        $this->assertFalse(User::where('phone', '+2250202020202')->exists());
        $this->assertNull($this->company->fresh()->legacy_import_used_at);

        $this->send($key, $payload)->assertOk()->assertJsonPath('data.counts.couriers_suspended', 1);
        $this->send($key, $payload)->assertOk()->assertJsonPath('data.counts.couriers', 0)->assertJsonCount(1, 'data.skipped');
        $this->assertSame(1, User::where('phone', '+2250202020202')->count());
    }

    public function test_only_a_valid_key_is_accepted(): void
    {
        $payload = ['step' => 'livreurs', 'livreurs' => [['id' => 9, 'nom' => 'Koffi', 'phone' => '0700000099', 'status' => 'active', 'user_id' => null]], 'users' => []];

        $this->send('jbm_inconnue', $payload)->assertUnauthorized();
        $this->postJson('/api/legacy-import', $payload)->assertUnauthorized();

        // Clé remplacée, désactivée ou expirée
        $old = $this->key();
        $key = $this->key();
        $this->send($old, $payload)->assertUnauthorized();
        $this->travel(8)->days();
        $this->send($key, $payload)->assertUnauthorized();
        $this->travelBack();

        $key = $this->key();
        $this->deleteJson('/api/v1/company/legacy-import/key')->assertOk()->assertJsonPath('data.active', false);
        $this->send($key, $payload)->assertUnauthorized();
        $this->assertFalse(User::where('phone', '+2250700000099')->exists());

        // Paquet mal formé
        $key = $this->key();
        $this->send($key, ['step' => 'commandes', 'users' => []])->assertUnprocessable();
    }

    public function test_key_and_module_are_for_administrators(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/company/legacy-import')->assertOk()->assertJsonPath('data.active', false);
        $this->postJson('/api/v1/company/legacy-import/key')->assertCreated()
            ->assertJsonPath('data.active', true)
            ->assertJsonMissingPath('data.legacy_import_key_hash');
        $this->get('/api/v1/company/legacy-import/module')->assertOk()->assertDownload('JibiatMigrationController.php');

        Sanctum::actingAs($this->courierA->user);
        $this->postJson('/api/v1/company/legacy-import/key')->assertForbidden();
        $this->get('/api/v1/company/legacy-import/module')->assertForbidden();
    }
}
