<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class PlatformStatus extends Command
{
    protected $signature = 'platform:status
        {--rename= : Change l\'adresse d\'une entreprise : ancienne:nouvelle (ex. livraison-express-ci:express)}';

    protected $description = 'Adresses de la plateforme (console, entreprises) et points à corriger avant la mise en service';

    public function handle(): int
    {
        if ($this->option('rename') !== null && ! $this->rename((string) $this->option('rename'))) {
            return self::FAILURE;
        }

        if (! Tenancy::enabled()) {
            $this->warn('Plateforme désactivée (PLATFORM_DOMAIN vide) : une seule entreprise, à l\'adresse '.config('app.url').'.');

            return self::SUCCESS;
        }

        $domain = Tenancy::domain();
        $scheme = config('platform.scheme');
        $problems = 0;

        $this->line("Présentation : {$scheme}://{$domain}");
        $this->line("Console      : {$scheme}://".config('platform.admin_subdomain').".{$domain}");

        $roots = User::role(Role::SuperAdmin->value)->count();
        $this->line("Super administrateurs : {$roots}");
        if ($roots === 0) {
            $problems++;
            $this->warn('  Aucun : créez-en un avec « php artisan app:create-super-admin ».');
        }

        $companies = Company::query()->orderBy('name')->get();
        $this->newLine();
        $this->table(['Entreprise', 'Statut', 'Adresse', 'À corriger'], $companies->map(function (Company $company) use (&$problems) {
            $issue = $this->slugIssue($company);
            $problems += $issue ? 1 : 0;

            return [$company->name, $company->status->value, $company->url(), $issue ?? ''];
        }));

        if ($problems > 0) {
            $this->warn("{$problems} point(s) à corriger. Adresse d'une entreprise : php artisan platform:status --rename=ancienne:nouvelle");

            return self::FAILURE;
        }

        $this->info('Tout est prêt.');

        return self::SUCCESS;
    }

    private function slugIssue(Company $company): ?string
    {
        $validator = Validator::make(['slug' => $company->slug], ['slug' => ['required', ...Company::slugRules($company)]]);

        return $validator->fails() ? 'adresse invalide ou réservée' : null;
    }

    private function rename(string $option): bool
    {
        [$from, $to] = array_pad(explode(':', $option, 2), 2, '');
        $company = Company::query()->where('slug', trim($from))->first();

        if (! $company) {
            $this->error("Aucune entreprise à l'adresse « {$from} ».");

            return false;
        }

        $validator = Validator::make(['slug' => trim($to)], ['slug' => ['required', ...Company::slugRules($company)]], [], ['slug' => 'nouvelle adresse']);
        if ($validator->fails()) {
            $this->error($validator->errors()->first('slug'));

            return false;
        }

        $company->update(['slug' => trim($to)]);
        $this->info("{$company->name} : adresse « {$from} » remplacée par « {$company->slug} ».");
        $this->newLine();

        return true;
    }
}
