<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\PreprodGate;
use App\Models\User;
use App\Support\Branding;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

/**
 * Préproduction : comptes de démonstration de l'entreprise de l'adresse (mot de passe « password »),
 * affichés sur les pages de connexion pour tester sans chercher les accès. Jamais le super
 * administrateur, jamais en production ni sans le code d'accès de la préproduction.
 */
class DemoAccountController extends Controller
{
    private const DEMO_PASSWORD = 'password';

    /** Espace de connexion de chaque rôle, dans l'ordre d'affichage */
    private const SPACES = [
        Role::Admin->value => 'staff',
        Role::Dispatcher->value => 'staff',
        Role::Cashier->value => 'staff',
        Role::HubAgent->value => 'staff',
        Role::Courier->value => 'courier',
        Role::MerchantOwner->value => 'merchant',
        Role::MerchantStaff->value => 'merchant',
    ];

    public function __invoke(): JsonResponse
    {
        $company = Branding::company();
        abort_unless(PreprodGate::active() && $company, 404);

        // Quelques comptes par espace (vérifier un mot de passe coûte du temps)
        $accounts = collect(array_unique(self::SPACES))->flatMap(fn (string $space) => User::query()
            ->with(['roles', 'merchant'])
            ->where('company_id', $company->id)
            ->where('status', UserStatus::Active->value)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', array_keys(self::SPACES, $space, true)))
            ->orderBy('id')
            ->limit(8)
            ->get())
            // Seuls les comptes qui ont encore le mot de passe de démonstration
            ->filter(fn (User $user) => $this->hasDemoPassword($user))
            ->map(function (User $user) {
                $role = $user->primaryRole();

                return [
                    'name' => $user->merchant?->business_name ? "{$user->name} ({$user->merchant->business_name})" : $user->name,
                    'role' => $role?->value,
                    'role_label' => $role?->label(),
                    'space' => self::SPACES[$role?->value] ?? null,
                    // Numéro tel qu'on le tape : 0700000001 plutôt que +2250700000001
                    'login' => str_starts_with((string) $user->phone, '+225') ? substr($user->phone, 4) : $user->phone,
                    'password' => self::DEMO_PASSWORD,
                ];
            })
            ->filter(fn (array $account) => $account['space'] !== null)
            ->sortBy(fn (array $account) => array_search($account['role'], array_keys(self::SPACES), true))
            ->values();

        return response()->json(['data' => $accounts]);
    }

    private function hasDemoPassword(User $user): bool
    {
        try {
            return Hash::check(self::DEMO_PASSWORD, (string) $user->password);
        } catch (\RuntimeException) {
            // Mot de passe dans un autre format (repris d'ailleurs) : pas un compte de test
            return false;
        }
    }
}
