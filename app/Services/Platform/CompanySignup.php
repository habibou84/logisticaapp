<?php

namespace App\Services\Platform;

use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Notifications\CompanySignedUp;
use App\Services\CompanyProvisioner;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Inscription d'une entreprise de livraison en libre-service (admin.jibiat.com/creer-mon-entreprise) :
 * l'entreprise choisit son adresse et sa formule, son espace est préparé et son essai
 * gratuit commence aussitôt ; le super administrateur est prévenu.
 */
class CompanySignup
{
    public const SOURCE = 'signup';

    // Entreprises inscrites par jour depuis une même adresse IP
    public const MAX_PER_IP_PER_DAY = 3;

    public function __construct(private readonly CompanyProvisioner $provisioner) {}

    /**
     * Inscriptions ouvertes : plateforme configurée, réglage de la console et au moins une
     * formule proposée (une entreprise inscrite seule n'est jamais « sans formule »).
     */
    public function isOpen(): bool
    {
        return Tenancy::enabled() && self::enabledInConsole() && $this->plans()->isNotEmpty();
    }

    public static function enabledInConsole(): bool
    {
        return PlatformSetting::get('company_signup', '1') === '1';
    }

    /**
     * @return Collection<int, Plan>
     */
    public function plans(): Collection
    {
        return Plan::query()->where('is_active', true)->orderBy('position')->orderBy('price_monthly')->get();
    }

    /**
     * Adresse demandée : libre ou non (avec la raison).
     *
     * @return array{slug: string, available: bool, message: ?string, url: ?string}
     */
    public function slugStatus(string $slug): array
    {
        $slug = mb_strtolower(trim($slug));
        $validator = Validator::make(['slug' => $slug], ['slug' => ['required', ...Company::slugRules()]], [
            'slug.unique' => 'Cette adresse est déjà prise.',
            'slug.not_in' => 'Cette adresse est réservée.',
            'slug.regex' => 'Minuscules, chiffres et tirets seulement (sans tiret au début ni à la fin).',
        ], ['slug' => 'adresse']);

        $available = ! $validator->fails();

        return [
            'slug' => $slug,
            'available' => $available,
            'message' => $available ? null : $validator->errors()->first('slug'),
            'url' => $available && Tenancy::enabled() ? config('platform.scheme').'://'.$slug.'.'.Tenancy::domain() : null,
        ];
    }

    /**
     * Crée l'entreprise, prépare son espace et son premier administrateur.
     *
     * @param  array{name: string, slug: string, plan_id: int, admin_name: string, phone: string, email?: ?string, password: string}  $data
     * @return array{0: Company, 1: User}
     */
    public function register(array $data, ?string $ip = null): array
    {
        if (! $this->isOpen()) {
            throw new BusinessRuleException('Les inscriptions sont fermées pour le moment. Contactez-nous pour ouvrir votre espace.');
        }

        // Un même numéro n'ouvre qu'une entreprise en ligne (un seul essai gratuit)
        $existing = User::query()->where('phone', $data['phone'])
            ->whereHas('company', fn ($q) => $q->where('source', self::SOURCE))
            ->with('company')->first();
        if ($existing !== null) {
            throw new BusinessRuleException('Ce numéro a déjà ouvert une entreprise : '.$existing->company->url().'. Connectez-vous à cette adresse, ou contactez-nous.', 'phone');
        }

        if ($ip !== null && Company::query()->where('signup_ip', $ip)->where('created_at', '>', now()->subDay())->count() >= self::MAX_PER_IP_PER_DAY) {
            throw new BusinessRuleException('Trop d\'inscriptions depuis cette connexion. Réessayez demain ou contactez-nous.');
        }

        [$company, $user] = DB::transaction(function () use ($data, $ip) {
            $trialDays = (int) config('platform.trial_days');

            $company = Company::create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'plan_id' => $data['plan_id'],
                'trial_ends_at' => $trialDays > 0 ? now()->addDays($trialDays)->toDateString() : null,
            ]);
            $company->forceFill(['source' => self::SOURCE, 'signup_ip' => $ip])->save();

            $this->provisioner->provision($company);

            $user = User::create([
                'company_id' => $company->id,
                'name' => $data['admin_name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'password' => $data['password'],
            ]);
            $user->assignRole(Role::Admin->value);

            return [$company, $user];
        });

        $this->notifySuperAdmins($company->load('plan'));

        return [$company, $user];
    }

    private function notifySuperAdmins(Company $company): void
    {
        $admins = User::query()->whereNull('company_id')->where('status', 'active')->role(Role::SuperAdmin->value)->get();

        // L'entreprise est créée : un incident d'envoi ne doit pas faire échouer l'inscription
        try {
            if ($admins->isNotEmpty()) {
                Notification::send($admins, new CompanySignedUp($company));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
