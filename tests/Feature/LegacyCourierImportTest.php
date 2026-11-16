<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Reprise des livreurs de l'ancienne application : une fiche par numéro (la plus récemment
 * utilisée), numéro de pièce, photo et pièce d'identité copiées depuis son « storage/app/public ».
 */
class LegacyCourierImportTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private string $dir;

    private string $file;

    private string $files;

    private const HASH = '$2y$10$l4EdonX1RMloN0UFYN1.Z.TGDzAlof1zNELfbYOhY6FO67hOU6tTu';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Storage::fake('local');

        $this->dir = sys_get_temp_dir().'/legacy-couriers-'.uniqid();
        $this->files = "{$this->dir}/public";
        File::ensureDirectoryExists("{$this->files}/image");
        File::ensureDirectoryExists("{$this->files}/piece");
        // Fichier temporaire gardé le temps de la copie
        $photo = UploadedFile::fake()->image('a.jpg', 200, 200);
        $piece = UploadedFile::fake()->image('b.jpg', 200, 150);
        copy($photo->getPathname(), "{$this->files}/image/kone.jpeg");
        copy($piece->getPathname(), "{$this->files}/piece/kone.jpeg");
        file_put_contents("{$this->files}/image/faux.jpeg", 'pas une image');
        // Hors du dossier donné : jamais lu
        file_put_contents("{$this->dir}/secret.jpeg", 'secret');

        $hash = self::HASH;
        $this->file = "{$this->dir}/livreurs.sql";
        file_put_contents($this->file, <<<SQL
            INSERT INTO `users` (`id`, `name`, `email`, `phone`, `usertype`, `password`, `created_at`, `livreur_id`, `client_id`) VALUES
            (1, 'Admin', 'admin@ancien.ci', '0700000099', 'admin', '{$hash}', NULL, NULL, NULL),
            (2, 'Client', 'client@ancien.ci', '0700000098', 'client', '{$hash}', NULL, NULL, 9),
            (10, 'Kone Cedric', 'kone@ancien.ci', '0505118075', 'livreur', '{$hash}', '2024-06-11 08:10:51', 50, NULL),
            (11, 'Kone C.', 'kone2@ancien.ci', '0505118075', 'livreur', '{$hash}', '2024-07-20 07:55:55', 51, NULL),
            (12, 'Ali', 'ali@ancien.ci', '0707070707', 'livreur', '{$hash}', NULL, 52, NULL),
            (13, 'Ali bis', 'ali2@ancien.ci', '0707070707', 'livreur', '{$hash}', NULL, 53, NULL),
            (14, 'Faux', 'faux@ancien.ci', '0101010199', 'livreur', '{$hash}', NULL, 54, NULL);
            INSERT INTO `livreurs` (`id`, `created_at`, `updated_at`, `nom`, `phone`, `adresse`, `city`, `pieces`, `status`, `user_id`, `geotime`, `photo`, `certified_at`, `piece_link`) VALUES
            (50, '2024-06-11 08:10:51', '2026-01-10 10:00:00', 'Kone Ferelaha Cédric', '0505118075', NULL, 'Yopougon', 'CI0012345', 'active', 10, '2026-11-13 19:17:21', 'image/kone.jpeg', '2024-06-12 00:00:00', 'piece/kone.jpeg'),
            (51, '2024-07-20 07:55:55', '2026-06-01 10:00:00', 'Kone Cedric', '0505118075', NULL, NULL, NULL, 'active', 11, '2024-07-24 18:05:48', NULL, NULL, NULL),
            (52, NULL, '2025-01-01 10:00:00', 'Ali', '0707070707', NULL, NULL, '00000', 'active', 12, NULL, 'image/absente.jpeg', NULL, '../secret.jpeg'),
            (53, NULL, '2025-03-01 10:00:00', 'Ali bis', '0707070707', NULL, NULL, NULL, 'active', 13, NULL, NULL, NULL, NULL),
            (54, NULL, NULL, 'Faux', '0101010199', NULL, NULL, NULL, 'active', 14, NULL, 'image/faux.jpeg', NULL, NULL);
            SQL);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function import(array $options = [])
    {
        return $this->artisan('legacy:import', ['file' => $this->file, ...$options]);
    }

    public function test_duplicate_phone_keeps_the_most_recently_used_record(): void
    {
        $this->import()->assertSuccessful();

        // Dernière position la plus récente : la fiche 50, même si la 51 a été modifiée après
        $kone = User::where('phone', '+2250505118075')->sole();
        $this->assertSame('Kone Ferelaha Cédric', $kone->name);
        $this->assertSame('kone@ancien.ci', $kone->email);
        // Sans position : dernière mise à jour
        $this->assertSame('Ali bis', User::where('phone', '+2250707070707')->sole()->name);

        // Clients et équipe : pas repris
        $this->assertFalse(User::whereIn('phone', ['+2250700000099', '+2250700000098'])->exists());
    }

    public function test_report_names_the_kept_record(): void
    {
        $this->import(['--dry-run' => true])
            ->expectsOutputToContain('que la fiche n° 50 (Kone Ferelaha Cédric), plus récemment utilisée')
            ->expectsOutputToContain('que la fiche n° 53 (Ali bis), plus récemment utilisée')
            ->assertSuccessful();
    }

    public function test_identity_and_photos_are_copied_from_the_old_files(): void
    {
        $this->import(['--files' => $this->files])
            ->expectsTable(['', 'Nombre'], [
                ['Clients importés', 0],
                ['Autres comptes des clients', 0],
                ['Livreurs importés', 3],
                ['Photos de livreurs reprises', 1],
                ['Pièces d\'identité reprises', 1],
                ['Lignes non importées', 2],
                ['Points à vérifier', 1],
                ['Comptes de l\'équipe non repris (à recréer)', 1],
            ])
            ->assertSuccessful();

        $kone = User::where('phone', '+2250505118075')->sole()->courier;
        $this->assertSame('CI0012345', $kone->id_number);
        $this->assertSame('Commune : Yopougon · Certifié le 12/06/2024', $kone->notes);
        Storage::disk('local')->assertExists($kone->photo_path);
        Storage::disk('local')->assertExists($kone->id_document_path);
        $this->assertStringStartsWith("couriers/{$kone->id}/", $kone->photo_path);
        $this->assertSame(file_get_contents("{$this->files}/image/kone.jpeg"), Storage::disk('local')->get($kone->photo_path));

        // Pas une image : pas reprise, signalée
        $faux = User::where('phone', '+2250101010199')->sole()->courier;
        $this->assertNull($faux->photo_path);
    }

    public function test_missing_unreadable_or_outside_files_are_reported(): void
    {
        // Simulation : fichiers vérifiés, rien copié
        $this->import(['--files' => $this->files, '--dry-run' => true])
            ->expectsOutputToContain('photo introuvable ou illisible : « image/faux.jpeg »')
            ->assertSuccessful();

        Storage::disk('local')->assertDirectoryEmpty('/');
        $this->assertFalse(User::where('phone', '+2250505118075')->exists());
    }

    public function test_path_outside_the_files_folder_is_refused(): void
    {
        file_put_contents($this->file, str_replace("'00000', 'active', 12, NULL, 'image/absente.jpeg'", "'00000', 'active', 12, '2027-01-01 00:00:00', 'image/absente.jpeg'", file_get_contents($this->file)));

        $this->import(['--files' => $this->files])
            ->expectsOutputToContain('photo introuvable ou illisible : « image/absente.jpeg »')
            ->expectsOutputToContain('pièce d\'identité introuvable ou illisible : « ../secret.jpeg »')
            ->assertSuccessful();

        $ali = User::where('phone', '+2250707070707')->sole()->courier;
        $this->assertSame('Ali', $ali->user->name);
        // Que des zéros : pas un numéro de pièce
        $this->assertNull($ali->id_number);
        $this->assertNull($ali->id_document_path);
    }

    public function test_unknown_files_folder_is_refused(): void
    {
        $this->import(['--files' => '/dossier/absent'])
            ->expectsOutputToContain('Dossier des fichiers introuvable')
            ->assertFailed();
    }
}
