<?php

namespace App\Services\Legacy;

use App\Enums\MerchantStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\User;
use App\Models\Zone;
use App\Services\Orders\ZoneResolver;
use App\Services\Platform\Subscription;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reprise des comptes de l'ancienne application (Laravel 5.8, une base par entreprise) :
 * e-commerçants (table « clients ») et livreurs (« livreurs »), avec leur compte de
 * connexion (« users » de type « client » ou « livreur ») et les autres comptes « client »
 * d'une boutique (ses employés). Les comptes de l'équipe (autres types) ne sont pas repris :
 * ils se recréent dans la nouvelle application, et une fiche client rattachée à un compte de
 * l'équipe (le compte client de l'administrateur) est ignorée. Les mots de passe bcrypt sont
 * repris tels quels : chacun garde son numéro et son mot de passe. Aucun message n'est envoyé.
 *
 * Les lignes arrivent d'un export SQL (commande legacy:import) ou par paquets, envoyées
 * par le bouton de l'ancienne application (voir legacy/laravel-5.8). Un numéro déjà
 * présent dans l'entreprise n'est pas réimporté : relancer l'import ne crée pas de doublon.
 */
class LegacyAccountsImporter
{
    /** Type du compte de connexion (« users.usertype ») de chaque fiche reprise */
    private const CLIENT_TYPE = 'client';

    private const COURIER_TYPE = 'livreur';

    /** Colonnes lues dans chaque table (absentes d'une ancienne base : null) */
    private const COLUMNS = [
        'users' => ['id', 'name', 'email', 'phone', 'usertype', 'password', 'created_at', 'livreur_id', 'client_id'],
        'clients' => ['id', 'nom', 'phone', 'city', 'adresse', 'goods_type', 'user_id', 'latitude', 'longitude', 'created_at', 'updated_at'],
        'livreurs' => ['id', 'nom', 'phone', 'adresse', 'city', 'status', 'user_id'],
    ];

    /** @var array<string, int> */
    private array $counts;

    /** @var list<array{type: string, id: string, name: string, reason: string}> */
    private array $skipped;

    /** @var list<array{type: string, id: string, name: string, reason: string}> */
    private array $warnings;

    /** @var Collection<int, Zone> */
    private Collection $zones;

    public function __construct(
        private readonly ZoneResolver $zoneResolver,
        private readonly Subscription $subscription,
    ) {}

    /**
     * Export SQL complet (tables clients, livreurs et users).
     *
     * @return array{counts: array<string, int>, skipped: list<array<string, string>>, warnings: list<array<string, string>>}
     */
    public function import(Company $company, SqlDumpReader $dump, bool $dryRun = false): array
    {
        $rows = fn (string $table) => $dump->hasTable($table) ? iterator_to_array($dump->rows($table), false) : [];
        $users = $rows('users');

        $report = $this->importRows($company, $users, $rows('clients'), $rows('livreurs'), $dryRun);
        // Comptes de l'équipe (ni « client » ni « livreur ») : à recréer dans la nouvelle application
        $report['counts']['staff_not_imported'] = collect($users)
            ->reject(fn (array $u) => $this->isType($u, self::CLIENT_TYPE) || $this->isType($u, self::COURIER_TYPE))
            ->count();

        return $report;
    }

    /**
     * Lignes de l'ancienne base, valeurs en texte (null pour NULL). Les comptes « users » liés à un
     * client ou à un livreur de l'envoi sont repris avec lui ; les autres ne sont pas repris.
     *
     * @param  list<array<string, string|null>>  $users
     * @param  list<array<string, string|null>>  $clients
     * @param  list<array<string, string|null>>  $livreurs
     * @return array{counts: array<string, int>, skipped: list<array<string, string>>, warnings: list<array<string, string>>}
     */
    public function importRows(Company $company, array $users, array $clients, array $livreurs, bool $dryRun = false): array
    {
        $this->counts = ['merchants' => 0, 'merchant_users' => 0, 'couriers' => 0, 'couriers_suspended' => 0];
        $this->skipped = [];
        $this->warnings = [];
        $this->zones = Zone::forCompany($company->id)->active()->with('parent')->get();

        [$users, $clients, $livreurs] = [$this->complete($users, 'users'), $this->complete($clients, 'clients'), $this->complete($livreurs, 'livreurs')];
        $users = collect($users)->keyBy('id');

        DB::beginTransaction();

        try {
            foreach ($clients as $client) {
                $user = $this->accountOf($users, $client['user_id'], 'client_id', $client['id']);
                if ($user !== null && ! $this->isType($user, self::CLIENT_TYPE)) {
                    $this->skip('e-commerçant', $client['id'], $this->clean($client['nom']), 'rattaché à un compte de l\'équipe (type « '.($this->clean($user['usertype']) ?? 'aucun').' ») : non repris');

                    continue;
                }
                // Autres comptes « client » rattachés à la boutique (users.client_id) : ses employés
                $others = $users->filter(fn (array $u) => $u['client_id'] === $client['id'] && $u['id'] !== ($user['id'] ?? null) && $this->isType($u, self::CLIENT_TYPE));
                $this->importMerchant($company, $client, $user, $others->values()->all());
            }

            foreach ($livreurs as $courier) {
                $user = $this->accountOf($users, $courier['user_id'], 'livreur_id', $courier['id']);
                if ($user !== null && ! $this->isType($user, self::COURIER_TYPE)) {
                    $this->skip('livreur', $courier['id'], $this->clean($courier['nom']), 'rattaché à un compte de l\'équipe (type « '.($this->clean($user['usertype']) ?? 'aucun').' ») : non repris');

                    continue;
                }
                $this->importCourier($company, $courier, $user);
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return ['counts' => $this->counts, 'skipped' => $this->skipped, 'warnings' => $this->warnings];
    }

    /**
     * @param  array<string, string|null>  $client
     * @param  array<string, string|null>|null  $user
     * @param  list<array<string, string|null>>  $others
     */
    private function importMerchant(Company $company, array $client, ?array $user, array $others = []): void
    {
        $name = $this->clean($client['nom']) ?: $this->clean($user['name'] ?? null);
        $phone = PhoneNumber::normalize($user['phone'] ?? null) ?? PhoneNumber::normalize($client['phone']);

        if ($phone === null) {
            $this->skip('e-commerçant', $client['id'], $name, 'numéro absent ou invalide : « '.($client['phone'] ?? '').' »');

            return;
        }

        if (User::takenIn($company->id, 'phone', $phone) || Merchant::forCompany($company->id)->where('phone', $phone)->exists()) {
            $this->skip('e-commerçant', $client['id'], $name, 'numéro '.PhoneNumber::display($phone).' déjà présent (déjà importé ou déjà inscrit)');

            return;
        }

        $email = $this->email($company, $user['email'] ?? null);
        $zone = $this->zone($client['city']) ?? $this->zone($client['adresse']);
        [$lat, $lng] = $this->position($client['latitude'], $client['longitude']);

        if ($zone === null) {
            $this->warn('e-commerçant', $client['id'], $name, 'commune « '.($client['city'] ?: $client['adresse']).' » non reconnue : zone de ramassage à choisir');
        }

        $merchant = new Merchant([
            'company_id' => $company->id,
            'business_name' => $name ?: 'Boutique '.PhoneNumber::display($phone),
            'contact_name' => $this->clean($user['name'] ?? null),
            'phone' => $phone,
            'email' => $email,
            'pickup_zone_id' => $zone?->id,
            'pickup_address' => collect([$this->clean($client['adresse']), $zone ? null : $this->clean($client['city'])])->filter()->implode(', ') ?: null,
            'pickup_lat' => $lat,
            'pickup_lng' => $lng,
            'status' => MerchantStatus::Active,
            'notes' => $this->clean($client['goods_type']) ? 'Marchandises : '.$this->clean($client['goods_type']) : null,
        ]);
        $merchant->forceFill([
            'source' => 'import',
            'pickup_location_source' => $lat !== null ? Merchant::LOCATION_STAFF : null,
            'pickup_located_at' => $lat !== null ? $this->date($client['updated_at']) : null,
            'created_at' => $this->date($client['created_at']) ?? now(),
        ])->save();

        $this->account($company, $user, [
            'merchant_id' => $merchant->id,
            'name' => $this->clean($user['name'] ?? null) ?: $merchant->business_name,
            'phone' => $phone,
            'email' => $email,
            'status' => UserStatus::Active,
        ], Role::MerchantOwner, 'e-commerçant', $client['id'], $merchant->business_name);

        $this->counts['merchants']++;

        foreach ($others as $other) {
            $this->importMerchantUser($company, $merchant, $other);
        }
    }

    /**
     * Autre compte de la boutique (employé) : même accès à l'espace e-commerçant que dans l'ancienne application.
     *
     * @param  array<string, string|null>  $user
     */
    private function importMerchantUser(Company $company, Merchant $merchant, array $user): void
    {
        $name = $this->clean($user['name'] ?? null);
        $phone = PhoneNumber::normalize($user['phone'] ?? null);

        if ($phone === null) {
            $this->skip('compte e-commerçant', $user['id'], $name, 'numéro absent ou invalide : « '.($user['phone'] ?? '').' »');

            return;
        }
        if (User::takenIn($company->id, 'phone', $phone)) {
            $this->skip('compte e-commerçant', $user['id'], $name, 'numéro '.PhoneNumber::display($phone).' déjà présent (déjà importé ou compte existant)');

            return;
        }

        $this->account($company, $user, [
            'merchant_id' => $merchant->id,
            'name' => $name ?: 'Compte '.PhoneNumber::display($phone),
            'phone' => $phone,
            'email' => $this->email($company, $user['email'] ?? null),
            'status' => UserStatus::Active,
        ], Role::MerchantStaff, 'compte e-commerçant', $user['id'], $name);

        $this->counts['merchant_users']++;
    }

    /**
     * @param  array<string, string|null>  $courier
     * @param  array<string, string|null>|null  $user
     */
    private function importCourier(Company $company, array $courier, ?array $user): void
    {
        $name = $this->clean($courier['nom']) ?: $this->clean($user['name'] ?? null);
        $phone = PhoneNumber::normalize($user['phone'] ?? null) ?? PhoneNumber::normalize($courier['phone']);

        if ($phone === null) {
            $this->skip('livreur', $courier['id'], $name, 'numéro absent ou invalide : « '.($courier['phone'] ?? '').' »');

            return;
        }

        if (User::takenIn($company->id, 'phone', $phone)) {
            $this->skip('livreur', $courier['id'], $name, 'numéro '.PhoneNumber::display($phone).' déjà présent (déjà importé ou compte existant)');

            return;
        }

        // Livreur inactif dans l'ancienne application, ou formule au maximum : compte suspendu
        $active = ($courier['status'] ?? 'active') === 'active';
        $max = $company->loadMissing('plan')->plan?->max_couriers;
        if ($active && $max !== null && $this->subscription->activeCouriers($company) >= $max) {
            $active = false;
            $this->warn('livreur', $courier['id'], $name, "importé suspendu : la formule permet {$max} livreur(s) actif(s)");
        }

        $account = $this->account($company, $user, [
            'name' => $name ?: 'Livreur '.PhoneNumber::display($phone),
            'phone' => $phone,
            'email' => $this->email($company, $user['email'] ?? null),
            'status' => $active ? UserStatus::Active : UserStatus::Suspended,
        ], Role::Courier, 'livreur', $courier['id'], $name);

        $account->syncCourierProfile();
        $notes = collect([
            $this->clean($courier['city']) ? 'Commune : '.$this->clean($courier['city']) : null,
            $this->clean($courier['adresse']) && $this->clean($courier['adresse']) !== $this->clean($courier['phone']) ? 'Adresse : '.$this->clean($courier['adresse']) : null,
        ])->filter()->implode(' · ');
        $account->courier()->withoutGlobalScopes()->first()?->update(['is_available' => false, 'notes' => $notes ?: null]);

        $this->counts['couriers']++;
        if (! $active) {
            $this->counts['couriers_suspended']++;
        }
    }

    /**
     * Compte de connexion, avec le mot de passe de l'ancienne application.
     *
     * @param  array<string, string|null>|null  $legacy
     * @param  array<string, mixed>  $attributes
     */
    private function account(Company $company, ?array $legacy, array $attributes, Role $role, string $type, ?string $legacyId, ?string $name): User
    {
        $user = new User(['company_id' => $company->id, ...$attributes]);
        $user->forceFill([
            'password' => Str::random(40),
            'created_at' => $this->date($legacy['created_at'] ?? null) ?? now(),
        ])->save();
        $user->assignRole($role->value);

        $hash = $this->bcrypt($legacy['password'] ?? null);
        if ($hash !== null) {
            // Hachage bcrypt conservé tel quel (sans repasser par le chiffrement du modèle)
            DB::table('users')->where('id', $user->id)->update(['password' => $hash]);
        } else {
            $this->warn($type, $legacyId, $name, $legacy === null
                ? 'pas de compte de connexion dans l\'ancienne application : mot de passe à créer (« Mot de passe oublié »)'
                : 'mot de passe non repris : à refaire avec « Mot de passe oublié »');
        }

        return $user;
    }

    /**
     * Compte lié à la fiche : « users.id = fiche.user_id », sinon « users.client_id / livreur_id = fiche.id ».
     *
     * @param  Collection<string, array<string, string|null>>  $users
     * @return array<string, string|null>|null
     */
    private function accountOf(Collection $users, ?string $userId, string $backReference, ?string $id): ?array
    {
        return ($userId !== null ? $users->get($userId) : null)
            ?? $users->first(fn (array $u) => ($u[$backReference] ?? null) !== null && $u[$backReference] === $id);
    }

    /** Type du compte dans l'ancienne application (« usertype »), sans tenir compte des majuscules */
    private function isType(array $user, string $type): bool
    {
        return mb_strtolower(trim((string) ($user['usertype'] ?? ''))) === $type;
    }

    /**
     * @param  list<array<string, string|null>>  $rows
     * @return list<array<string, string|null>>
     */
    private function complete(array $rows, string $table): array
    {
        $defaults = array_fill_keys(self::COLUMNS[$table], null);

        return array_map(fn (array $row) => $row + $defaults, $rows);
    }

    private function zone(?string $name): ?Zone
    {
        $name = $this->clean($name);
        if ($name === null || $this->zones->isEmpty()) {
            return null;
        }

        $found = $this->zoneResolver->match($name, $this->zones);

        return $found->count() === 1 ? $found->first() : null;
    }

    /**
     * @return array{0: float|null, 1: float|null}
     */
    private function position(?string $lat, ?string $lng): array
    {
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return [null, null];
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        return abs($lat) <= 90 && abs($lng) <= 180 && ($lat != 0 || $lng != 0) ? [$lat, $lng] : [null, null];
    }

    private function email(Company $company, ?string $email): ?string
    {
        $email = $this->clean($email);

        return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) && ! User::takenIn($company->id, 'email', mb_strtolower($email))
            ? mb_strtolower($email)
            : null;
    }

    /**
     * Hachage bcrypt de Laravel ($2y$) ; $2a$ et $2b$ sont équivalents pour PHP.
     */
    private function bcrypt(?string $hash): ?string
    {
        return $hash !== null && preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $hash)
            ? '$2y$'.substr($hash, 4)
            : null;
    }

    private function date(?string $value): ?Carbon
    {
        if ($value === null || str_starts_with($value, '0000')) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function clean(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : $value;
    }

    private function skip(string $type, ?string $id, ?string $name, string $reason): void
    {
        $this->skipped[] = ['type' => $type, 'id' => (string) $id, 'name' => (string) $name, 'reason' => $reason];
    }

    private function warn(string $type, ?string $id, ?string $name, string $reason): void
    {
        $this->warnings[] = ['type' => $type, 'id' => (string) $id, 'name' => (string) $name, 'reason' => $reason];
    }
}
