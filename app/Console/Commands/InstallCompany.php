<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Services\CompanyProvisioner;
use Database\Seeders\IncidentReasonsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Première mise en service : l'entreprise de livraison (communes d'Abidjan, grille tarifaire et plan
 * de paie vides) et son premier administrateur. Une seule entreprise par installation.
 */
class InstallCompany extends Command
{
    protected $signature = 'app:install
        {--name= : Nom de l\'entreprise de livraison}
        {--admin-name= : Nom de l\'administrateur}
        {--admin-phone= : Téléphone de l\'administrateur (identifiant de connexion)}
        {--admin-email= : E-mail de l\'administrateur (facultatif)}';

    protected $description = 'Crée l\'entreprise de livraison et son premier administrateur (première mise en service)';

    public function handle(CompanyProvisioner $provisioner): int
    {
        if (Company::query()->exists()) {
            $this->error('L\'entreprise est déjà créée : '.Company::query()->orderBy('id')->value('name').'.');
            $this->line('Pour un autre compte, créez-le dans Utilisateurs ; mot de passe perdu : php artisan user:password <téléphone>.');

            return self::FAILURE;
        }

        $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
        $this->callSilently('db:seed', ['--class' => IncidentReasonsSeeder::class, '--force' => true]);

        $data = [
            'name' => $this->option('name') ?: text('Nom de l\'entreprise de livraison', required: true),
            'admin_name' => $this->option('admin-name') ?: text('Nom de l\'administrateur', required: true),
            'admin_phone' => $this->option('admin-phone') ?: text('Téléphone de l\'administrateur', required: true),
            'admin_email' => $this->option('admin-email') ?: (text('E-mail de l\'administrateur (facultatif)') ?: null),
            'password' => password('Mot de passe de l\'administrateur (8 caractères minimum)', required: true),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_phone' => ['required', new PhoneNumber],
            'admin_email' => ['nullable', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
        ], [], [
            'name' => 'nom de l\'entreprise',
            'admin_name' => 'nom',
            'admin_phone' => 'téléphone',
            'admin_email' => 'e-mail',
            'password' => 'mot de passe',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $admin = DB::transaction(function () use ($data, $provisioner) {
            $company = Company::create(['name' => $data['name'], 'slug' => Company::freeSlug($data['name'])]);
            $provisioner->provision($company);

            $admin = User::create([
                'company_id' => $company->id,
                'name' => $data['admin_name'],
                'phone' => $data['admin_phone'],
                'email' => $data['admin_email'],
                'password' => $data['password'],
            ]);
            $admin->assignRole(Role::Admin->value);

            return $admin;
        });

        $this->info("Entreprise « {$data['name']} » créée.");
        $this->info("Administrateur : {$admin->phone}. Connexion : ".rtrim((string) config('app.url'), '/').'/admin');

        return self::SUCCESS;
    }
}
