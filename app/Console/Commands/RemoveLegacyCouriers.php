<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use App\Services\Legacy\SqlDumpReader;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Annule l'import des livreurs de l'ancienne application (legacy:import) : les comptes livreurs dont le numéro
 * figure dans le même export SQL sont supprimés définitivement (fiche, zones, barème, planning, positions,
 * véhicules attribués, photo et pièce d'identité). Les numéros redeviennent libres : les livreurs peuvent être
 * recréés à la main.
 *
 * Un livreur qui a encore de l'activité (courses, gains, avances, paies, versements) n'est pas supprimé : lancer
 * d'abord app:reset-activity. Sans --execute : simulation (liste des livreurs concernés).
 */
class RemoveLegacyCouriers extends Command
{
    protected $signature = 'legacy:remove-couriers
        {file : Le même export SQL de l\'ancienne application que pour legacy:import (.sql ou .sql.gz)}
        {--execute : Supprimer vraiment (sans cette option : simulation)}';

    protected $description = 'Supprime définitivement les livreurs importés de l\'ancienne application';

    /** Activité qui empêche la suppression d'un livreur (argent et courses) */
    private const ACTIVITY = [
        'order_assignments' => 'attributions de courses',
        'courier_earnings' => 'gains',
        'courier_advances' => 'avances',
        'courier_payouts' => 'paies',
        'courier_remittances' => 'versements à la caisse',
    ];

    public function handle(): int
    {
        if (Company::query()->count() !== 1) {
            $this->error('Cette commande attend une seule entreprise dans la base.');

            return self::FAILURE;
        }
        $company = Company::query()->firstOrFail();

        try {
            $dump = SqlDumpReader::fromFile($this->argument('file'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (! $dump->hasTable('livreurs')) {
            $this->error('Le fichier ne contient pas la table « livreurs ».');

            return self::FAILURE;
        }

        $phones = $this->legacyCourierPhones($dump);
        $couriers = User::query()->withoutGlobalScopes()->withTrashed()
            ->where('company_id', $company->id)
            ->whereIn('phone', $phones->all())
            ->with(['courier' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('name')->get()
            // Seulement des comptes livreurs : un compte de l'équipe au même numéro n'est jamais touché
            ->filter(fn (User $u) => $u->courier !== null && $u->hasRole(Role::Courier->value))
            ->values();

        $blocked = [];
        $rows = $couriers->map(function (User $user) use (&$blocked) {
            $activity = collect(self::ACTIVITY)
                ->map(fn ($label, $table) => [$label, DB::table($table)->where('courier_id', $user->courier->id)->count()])
                ->filter(fn ($a) => $a[1] > 0)
                ->map(fn ($a) => "{$a[1]} {$a[0]}")
                ->implode(', ');
            if ($activity !== '') {
                $blocked[] = $user->id;
            }

            return [$user->name, PhoneNumber::display($user->phone), $user->status?->value ?? '', $activity ?: '—'];
        });

        $this->info("Entreprise : {$company->name} — {$phones->count()} numéro(s) de livreurs dans l'export, {$couriers->count()} livreur(s) trouvé(s).");
        if ($couriers->isNotEmpty()) {
            $this->table(['Livreur', 'Téléphone', 'Statut', 'Activité (empêche la suppression)'], $rows->all());
        }

        if ($blocked !== []) {
            $this->error(count($blocked).' livreur(s) ont encore de l\'activité : lancez d\'abord php artisan app:reset-activity --execute. Rien n\'a été supprimé.');

            return self::FAILURE;
        }
        if ($couriers->isEmpty()) {
            $this->info('Aucun livreur importé à supprimer.');

            return self::SUCCESS;
        }
        if (! $this->option('execute')) {
            $this->warn('Simulation : rien n\'a été supprimé. Pour supprimer : ajoutez --execute');

            return self::SUCCESS;
        }

        $this->warn('Suppression DÉFINITIVE de '.$couriers->count().' livreur(s). Faites d\'abord une sauvegarde : bash deploy/backup.sh avant-retrait-livreurs');
        if ($this->ask('Tapez SUPPRIMER pour confirmer') !== 'SUPPRIMER') {
            $this->error('Abandon : rien n\'a été supprimé.');

            return self::FAILURE;
        }

        $files = $couriers->flatMap(fn (User $u) => [$u->courier->photo_path, $u->courier->id_document_path])->filter();
        $ids = $couriers->pluck('id')->all();
        DB::transaction(function () use ($ids) {
            // Tables sans clé étrangère vers les comptes (rôles, jetons, notifications, sessions)
            DB::table('model_has_roles')->where('model_type', (new User)->getMorphClass())->whereIn('model_id', $ids)->delete();
            DB::table('model_has_permissions')->where('model_type', (new User)->getMorphClass())->whereIn('model_id', $ids)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', (new User)->getMorphClass())->whereIn('tokenable_id', $ids)->delete();
            DB::table('notifications')->where('notifiable_type', (new User)->getMorphClass())->whereIn('notifiable_id', $ids)->delete();
            DB::table('sessions')->whereIn('user_id', $ids)->delete();
            // Le reste suit (fiche livreur et mécanicien, zones, barème, planning, positions, véhicules attribués…)
            DB::table('users')->whereIn('id', $ids)->delete();
        });
        Storage::disk('local')->delete($files->all());

        $this->info("Terminé : {$couriers->count()} livreur(s) supprimé(s) ; leurs numéros sont de nouveau libres.");

        return self::SUCCESS;
    }

    /**
     * Numéros des livreurs de l'export, comme legacy:import les a lus : celui du compte de connexion lié
     * (users.id = livreurs.user_id, ou users.livreur_id = livreurs.id), sinon celui de la fiche.
     *
     * @return Collection<int, string>
     */
    private function legacyCourierPhones(SqlDumpReader $dump): Collection
    {
        $users = collect($dump->hasTable('users') ? iterator_to_array($dump->rows('users'), false) : []);
        $byId = $users->keyBy('id');

        return collect(iterator_to_array($dump->rows('livreurs'), false))
            ->flatMap(function (array $courier) use ($users, $byId) {
                $account = (($courier['user_id'] ?? null) !== null ? $byId->get($courier['user_id']) : null)
                    ?? $users->first(fn ($u) => ($u['livreur_id'] ?? null) !== null && $u['livreur_id'] === ($courier['id'] ?? null));

                // Même règle que l'import : le numéro du compte, sinon celui de la fiche
                return [PhoneNumber::normalize($account['phone'] ?? null) ?? PhoneNumber::normalize($courier['phone'] ?? null)];
            })
            ->filter()->unique()->values();
    }
}
