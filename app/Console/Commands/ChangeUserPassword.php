<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;

class ChangeUserPassword extends Command
{
    protected $signature = 'user:password
        {login : Téléphone ou e-mail du compte}';

    protected $description = 'Change le mot de passe d\'un compte et le déconnecte de ses appareils';

    public function handle(): int
    {
        $user = $this->findUser();
        if (! $user) {
            return self::FAILURE;
        }

        $min = 8;
        $new = password("Nouveau mot de passe ({$min} caractères minimum)", required: true);
        if (mb_strlen($new) < $min) {
            $this->error("Le mot de passe doit contenir au moins {$min} caractères.");

            return self::FAILURE;
        }
        if (password('Confirmez le mot de passe', required: true) !== $new) {
            $this->error('Les deux mots de passe ne correspondent pas.');

            return self::FAILURE;
        }

        $user->update(['password' => $new]);
        // Comme depuis le back-office : un mot de passe changé déconnecte tous les appareils
        $user->tokens()->delete();

        $this->info("Mot de passe changé pour {$user->name}. Ses appareils sont déconnectés.");

        return self::SUCCESS;
    }

    private function findUser(): ?User
    {
        $login = trim((string) $this->argument('login'));
        $phone = PhoneNumber::normalize($login);

        $user = User::query()
            ->where(fn ($q) => $q->where('email', $login)->when($phone, fn ($q) => $q->orWhere('phone', $phone)))
            ->first();

        if ($user === null) {
            $this->error("Aucun compte « {$login} ».");
        }

        return $user;
    }
}
