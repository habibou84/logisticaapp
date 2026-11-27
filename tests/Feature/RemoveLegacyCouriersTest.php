<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Courier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Annulation de l'import des livreurs (php artisan legacy:remove-couriers) : les livreurs de l'export sont
 * supprimés définitivement, leurs numéros redeviennent libres ; les autres comptes ne sont jamais touchés.
 */
class RemoveLegacyCouriersTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private string $dir;

    private string $file;

    private const HASH = '$2y$10$l4EdonX1RMloN0UFYN1.Z.TGDzAlof1zNELfbYOhY6FO67hOU6tTu';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Storage::fake('local');

        $this->dir = sys_get_temp_dir().'/remove-couriers-'.uniqid();
        File::ensureDirectoryExists("{$this->dir}/public/image");
        // Fichier temporaire gardé le temps de la copie
        $photo = UploadedFile::fake()->image('a.jpg', 200, 200);
        copy($photo->getPathname(), "{$this->dir}/public/image/kone.jpeg");

        $hash = self::HASH;
        $this->file = "{$this->dir}/livreurs.sql";
        file_put_contents($this->file, <<<SQL
            INSERT INTO `users` (`id`, `name`, `email`, `phone`, `usertype`, `password`, `created_at`, `livreur_id`, `client_id`) VALUES
            (1, 'Admin', 'admin@ancien.ci', '0700000099', 'admin', '{$hash}', NULL, NULL, NULL),
            (10, 'Kone Cedric', 'kone@ancien.ci', '0505118075', 'livreur', '{$hash}', NULL, 50, NULL),
            (12, 'Ali', 'ali@ancien.ci', '0707070707', 'livreur', '{$hash}', NULL, 52, NULL),
            (14, 'Doublon équipe', NULL, '0101010199', 'livreur', '{$hash}', NULL, 54, NULL);
            INSERT INTO `livreurs` (`id`, `created_at`, `updated_at`, `nom`, `phone`, `adresse`, `city`, `pieces`, `status`, `user_id`, `geotime`, `photo`, `certified_at`, `piece_link`) VALUES
            (50, NULL, NULL, 'Kone Cédric', '0505118075', NULL, 'Yopougon', NULL, 'active', 10, NULL, 'image/kone.jpeg', NULL, NULL),
            (52, NULL, NULL, 'Ali', '0707070707', NULL, NULL, NULL, 'inactive', 12, NULL, NULL, NULL, NULL),
            (54, NULL, NULL, 'Doublon équipe', '0101010199', NULL, NULL, NULL, 'active', 14, NULL, NULL, NULL, NULL);
            SQL);

        // Membre de l'équipe qui a le numéro d'un livreur de l'export : l'import l'a sauté, le retrait l'ignore
        $this->userWithRole(Role::Dispatcher, ['name' => 'Répartiteur', 'phone' => '+2250101010199']);
        $this->artisan('legacy:import', ['file' => $this->file, '--files' => "{$this->dir}/public"])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function remove(bool $execute = true)
    {
        return $this->artisan('legacy:remove-couriers', ['file' => $this->file, '--execute' => $execute]);
    }

    public function test_the_simulation_lists_the_imported_couriers_only(): void
    {
        $this->remove(false)
            ->expectsOutputToContain('2 livreur(s) trouvé(s)')
            ->expectsOutputToContain('Simulation')
            ->assertSuccessful();

        $this->assertSame(2, User::whereIn('phone', ['+2250505118075', '+2250707070707'])->count());
    }

    public function test_imported_couriers_are_deleted_for_good_and_their_numbers_freed(): void
    {
        $kone = User::where('phone', '+2250505118075')->sole();
        $photo = $kone->courier->photo_path;
        Storage::disk('local')->assertExists($photo);
        $others = [$this->courierA->id, $this->courierB->id];

        $this->remove()->expectsQuestion('Tapez SUPPRIMER pour confirmer', 'SUPPRIMER')
            ->expectsOutputToContain('2 livreur(s) supprimé(s)')->assertSuccessful();

        // Définitivement (pas à la corbeille), fiche livreur et rôle compris
        $this->assertFalse(User::withTrashed()->whereIn('phone', ['+2250505118075', '+2250707070707'])->exists());
        $this->assertFalse(Courier::withoutGlobalScopes()->where('id', $kone->courier->id)->exists());
        $this->assertFalse(DB::table('model_has_roles')->where('model_id', $kone->id)->where('model_type', $kone->getMorphClass())->exists());
        Storage::disk('local')->assertMissing($photo);

        // Les autres livreurs et l'équipe restent, y compris le répartiteur au numéro d'un livreur de l'export
        $this->assertSame($others, Courier::withoutGlobalScopes()->whereIn('id', $others)->orderBy('id')->pluck('id')->all());
        $this->assertSame('Répartiteur', User::where('phone', '+2250101010199')->sole()->name);

        // Le numéro est libre : le livreur peut être recréé à la main
        $this->assertFalse(User::takenIn($this->company->id, 'phone', '+2250505118075'));
    }

    public function test_a_courier_with_activity_blocks_everything(): void
    {
        $kone = User::where('phone', '+2250505118075')->sole();
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $kone->courier->id])->assertOk();

        $this->remove()->expectsOutputToContain('app:reset-activity')->assertFailed();

        $this->assertSame(2, User::whereIn('phone', ['+2250505118075', '+2250707070707'])->count());
    }

    public function test_a_wrong_confirmation_deletes_nothing(): void
    {
        $this->remove()->expectsQuestion('Tapez SUPPRIMER pour confirmer', 'oui')->assertFailed();

        $this->assertSame(2, User::whereIn('phone', ['+2250505118075', '+2250707070707'])->count());
    }
}
