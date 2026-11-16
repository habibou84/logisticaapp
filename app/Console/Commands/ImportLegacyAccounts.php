<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Legacy\LegacyAccountsImporter;
use App\Services\Legacy\SqlDumpReader;
use Illuminate\Console\Command;
use RuntimeException;

class ImportLegacyAccounts extends Command
{
    protected $signature = 'legacy:import
        {file : Export SQL de l\'ancienne application (mysqldump ou phpMyAdmin : tables clients, livreurs et users, .sql ou .sql.gz)}
        {--files= : Dossier « storage/app/public » de l\'ancienne application, pour reprendre les photos des livreurs et de leur pièce d\'identité}
        {--dry-run : Simule l\'import et affiche le rapport, sans rien enregistrer}';

    protected $description = 'Reprend les clients et les livreurs de l\'ancienne application (mêmes numéros, mêmes mots de passe)';

    public function handle(LegacyAccountsImporter $importer): int
    {
        $company = Company::query()->orderBy('id')->first();
        if ($company === null) {
            $this->error('Créez d\'abord l\'entreprise : php artisan app:install.');

            return self::FAILURE;
        }

        $files = $this->option('files');
        if ($files !== null && ! is_dir($files)) {
            $this->error("Dossier des fichiers introuvable : {$files}");

            return self::FAILURE;
        }

        try {
            $dump = SqlDumpReader::fromFile($this->argument('file'));
            if (! $dump->hasTable('clients') && ! $dump->hasTable('livreurs')) {
                $this->error('Le fichier ne contient ni la table « clients » ni la table « livreurs ».');

                return self::FAILURE;
            }

            $report = $importer->import($company, $dump, (bool) $this->option('dry-run'), $files);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line($this->option('dry-run')
            ? "<comment>Simulation pour {$company->name} : rien n'a été enregistré.</comment>"
            : "<info>Import terminé pour {$company->name}.</info>");

        $c = $report['counts'];
        $this->table(['', 'Nombre'], [
            ['Clients importés', $c['merchants']],
            ['Autres comptes des clients', $c['merchant_users']],
            ['Livreurs importés', $c['couriers'].($c['couriers_suspended'] ? " (dont {$c['couriers_suspended']} suspendu(s))" : '')],
            // Photos : seulement avec le dossier des fichiers de l'ancienne application
            ...($files !== null ? [
                ['Photos de livreurs reprises', $c['courier_photos']],
                ['Pièces d\'identité reprises', $c['courier_id_documents']],
            ] : []),
            ['Lignes non importées', count($report['skipped'])],
            ['Points à vérifier', count($report['warnings'])],
            ['Comptes de l\'équipe non repris (à recréer)', $c['staff_not_imported']],
        ]);

        foreach (['skipped' => 'Non importés', 'warnings' => 'À vérifier'] as $key => $title) {
            if ($report[$key] !== []) {
                $this->line("<comment>{$title}</comment>");
                $this->table(['Type', 'N° ancien', 'Nom', 'Raison'], array_map(fn ($r) => [$r['type'], $r['id'], $r['name'], $r['reason']], $report[$key]));
            }
        }

        if ($this->option('dry-run')) {
            $this->line('Relancez sans --dry-run pour enregistrer.');
        }

        return self::SUCCESS;
    }
}
