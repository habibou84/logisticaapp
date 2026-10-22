<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Support\PhoneNumber as Phone;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateSuperAdmin extends Command
{
    protected $signature = 'app:create-super-admin';

    protected $description = 'Crée un super administrateur de la plateforme (à utiliser en production)';

    public function handle(): int
    {
        $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $data = [
            'name' => text('Nom', required: true),
            'phone' => text('Téléphone', required: true),
            'email' => text('E-mail (facultatif)') ?: null,
            'password' => password('Mot de passe (12 caractères minimum)', required: true),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', new PhoneNumber],
            'email' => ['nullable', 'email', User::uniqueIn(null, 'email')],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (User::takenIn(null, 'phone', Phone::normalize($data['phone']))) {
            $this->error('Ce numéro de téléphone est déjà utilisé.');

            return self::FAILURE;
        }

        $user = User::create([...$data, 'company_id' => null]);
        $user->assignRole(Role::SuperAdmin->value);
        $this->info("Super administrateur créé : {$user->phone}");

        return self::SUCCESS;
    }
}
