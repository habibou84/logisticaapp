<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

use function Laravel\Prompts\password;

class ChangeUserPassword extends Command
{
    protected $signature = 'user:password
        {login : Téléphone ou e-mail du compte}
        {--company= : Adresse de l\'entreprise (ex. express), ou « console » pour un super administrateur, si le numéro a un compte dans plusieurs}';

    protected $description = 'Change le mot de passe d\'un compte (super administrateur compris) et le déconnecte de ses appareils';

    public function handle(): int
    {
        $user = $this->findUser();
        if (! $user) {
            return self::FAILURE;
        }

        $min = $user->isSuperAdmin() ? 12 : 8;
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

        $this->info("Mot de passe changé pour {$user->name} ({$this->place($user)}). Ses appareils sont déconnectés.");

        return self::SUCCESS;
    }

    private function findUser(): ?User
    {
        $login = trim((string) $this->argument('login'));
        $phone = PhoneNumber::normalize($login);

        /** @var Collection<int, User> $users */
        $users = User::query()
            ->with('company')
            ->where(fn ($q) => $q->where('email', $login)->when($phone, fn ($q) => $q->orWhere('phone', $phone)))
            ->get();

        if (($company = $this->option('company')) !== null) {
            $users = $users->filter(fn (User $u) => $company === 'console' ? $u->company_id === null : $u->company?->slug === $company)->values();
        }

        if ($users->isEmpty()) {
            $this->error("Aucun compte « {$login} »".($company !== null ? " pour « {$company} »" : '').'.');

            return null;
        }

        if ($users->count() > 1) {
            $this->error("« {$login} » a un compte à plusieurs endroits. Précisez lequel avec --company :");
            foreach ($users as $u) {
                $this->line('  --company='.($u->company?->slug ?? 'console')."   {$u->name} ({$this->place($u)})");
            }

            return null;
        }

        return $users->first();
    }

    private function place(User $user): string
    {
        return $user->company?->name ?? 'console de la plateforme';
    }
}
