<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class DemoAccounts extends Command
{
    // Mot de passe des comptes créés par les données de démonstration (DemoSeeder)
    private const DEMO_PASSWORD = 'password';

    protected $signature = 'app:demo-accounts
        {--suspend : Suspendre ces comptes et les déconnecter}';

    protected $description = 'Repère les comptes actifs qui ont encore le mot de passe de démonstration « password »';

    public function handle(): int
    {
        $found = collect();

        User::query()->with(['company', 'roles'])->where('status', UserStatus::Active->value)
            ->chunkById(200, function ($users) use ($found) {
                foreach ($users as $user) {
                    if (Hash::check(self::DEMO_PASSWORD, $user->password)) {
                        $found->push($user);
                    }
                }
            });

        if ($found->isEmpty()) {
            $this->info('Aucun compte actif n\'a le mot de passe de démonstration.');

            return self::SUCCESS;
        }

        $this->table(['Nom', 'Téléphone', 'Rôle', 'Entreprise'], $found->map(fn (User $u) => [
            $u->name, $u->phone, $u->primaryRole()?->label() ?? '—', $u->company?->name ?? '—',
        ]));

        if (! $this->option('suspend')) {
            $this->warn("{$found->count()} compte(s) avec le mot de passe « password ». Pour les suspendre : php artisan app:demo-accounts --suspend");

            return self::FAILURE;
        }

        foreach ($found as $user) {
            $user->update(['status' => UserStatus::Suspended->value]);
            $user->tokens()->delete();
        }

        $this->info("{$found->count()} compte(s) suspendu(s) et déconnecté(s). Réactivation possible depuis le back-office (Utilisateurs).");

        return self::SUCCESS;
    }
}
