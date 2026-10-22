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
        {company : Adresse de l\'entreprise sur la plateforme (ex. rapide-express)}
        {file : Export SQL de l\'ancienne application (phpMyAdmin : tables clients, livreurs et users, .sql ou .sql.gz)}
        {--dry-run : Simule l\'import et affiche le rapport, sans rien enregistrer}';

    protected $description = 'Reprend les e-commerçants et les livreurs de l\'ancienne application (mêmes numéros, mêmes mots de passe)';

    public function handle(LegacyAccountsImporter $importer): int
    {
        $company = Company::where('slug', $this->argument('company'))->first();
        if ($company === null) {
            $this->error('Aucune entreprise à l\'adresse « '.$this->argument('company').' » (voir php artisan platform:status).');

            return self::FAILURE;
        }

        try {
            $dump = SqlDumpReader::fromFile($this->argument('file'));
            if (! $dump->hasTable('clients') && ! $dump->hasTable('livreurs')) {
                $this->error('Le fichier ne contient ni la table « clients » ni la table « livreurs ».');

                return self::FAILURE;
            }

            $report = $importer->import($company, $dump, (bool) $this->option('dry-run'));
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
            ['E-commerçants importés', $c['merchants']],
            ['Autres comptes des e-commerçants', $c['merchant_users']],
            ['Livreurs importés', $c['couriers'].($c['couriers_suspended'] ? " (dont {$c['couriers_suspended']} suspendu(s))" : '')],
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
