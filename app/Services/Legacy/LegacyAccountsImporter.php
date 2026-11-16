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
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reprise des comptes de l'ancienne application (Laravel 5.8, une base par entreprise) :
 * clients (table « clients ») et livreurs (« livreurs »), avec leur compte de
 * connexion (« users » de type « client » ou « livreur ») et les autres comptes « client »
 * d'une boutique (ses employés). Les comptes de l'équipe (autres types) ne sont pas repris :
 * ils se recréent dans la nouvelle application, et une fiche client rattachée à un compte de
 * l'équipe (le compte client de l'administrateur) est ignorée. Les mots de passe bcrypt sont
 * repris tels quels : chacun garde son numéro et son mot de passe. Aucun message n'est envoyé.
 *
 * Les lignes arrivent d'un export SQL (commande legacy:import) ou par paquets, envoyées
 * par le bouton de l'ancienne application (voir legacy/laravel-5.8). Un numéro déjà
 * présent dans l'entreprise n'est pas réimporté : relancer l'import ne crée pas de doublon.
 * Plusieurs fiches de livreur avec le même numéro : la plus récemment utilisée est reprise.
 *
 * Photos des livreurs et de leur pièce d'identité : reprises quand le dossier des fichiers de
 * l'ancienne application (son « storage/app/public ») est donné.
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
        'livreurs' => ['id', 'nom', 'phone', 'adresse', 'city', 'status', 'user_id', 'pieces', 'photo', 'piece_link', 'certified_at', 'geotime', 'created_at', 'updated_at'],
    ];

    /** Images reprises de l'ancienne application */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** Dossier des fichiers de l'ancienne application (null : photos non reprises) */
    private ?string $filesDir = null;

    private bool $dryRun = false;

    /** @var list<string> fichiers copiés pendant l'import (effacés s'il échoue) */
    private array $storedFiles = [];

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
    ) {}

    /**
     * Export SQL complet (tables clients, livreurs et users).
     *
     * @return array{counts: array<string, int>, skipped: list<array<string, string>>, warnings: list<array<string, string>>}
     */
    public function import(Company $company, SqlDumpReader $dump, bool $dryRun = false, ?string $filesDir = null): array
    {
        $rows = fn (string $table) => $dump->hasTable($table) ? iterator_to_array($dump->rows($table), false) : [];
        $users = $rows('users');

        $report = $this->importRows($company, $users, $rows('clients'), $rows('livreurs'), $dryRun, $filesDir);
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
     * @param  string|null  $filesDir  dossier « storage/app/public » de l'ancienne application (photos des livreurs)
     * @return array{counts: array<string, int>, skipped: list<array<string, string>>, warnings: list<array<string, string>>}
     */
    public function importRows(Company $company, array $users, array $clients, array $livreurs, bool $dryRun = false, ?string $filesDir = null): array
    {
        $this->counts = ['merchants' => 0, 'merchant_users' => 0, 'couriers' => 0, 'couriers_suspended' => 0, 'courier_photos' => 0, 'courier_id_documents' => 0];
        $this->skipped = [];
        $this->warnings = [];
        $this->storedFiles = [];
        $this->dryRun = $dryRun;
        $this->filesDir = $filesDir !== null ? (realpath($filesDir) ?: null) : null;
        $this->zones = Zone::forCompany($company->id)->active()->with('parent')->get();

        [$users, $clients, $livreurs] = [$this->complete($users, 'users'), $this->complete($clients, 'clients'), $this->complete($livreurs, 'livreurs')];
        $users = collect($users)->keyBy('id');

        DB::beginTransaction();

        try {
            foreach ($clients as $client) {
                $user = $this->accountOf($users, $client['user_id'], 'client_id', $client['id']);
                if ($user !== null && ! $this->isType($user, self::CLIENT_TYPE)) {
                    $this->skip('client', $client['id'], $this->clean($client['nom']), 'rattaché à un compte de l\'équipe (type « '.($this->clean($user['usertype']) ?? 'aucun').' ») : non repris');

                    continue;
                }
                // Autres comptes « client » rattachés à la boutique (users.client_id) : ses employés
                $others = $users->filter(fn (array $u) => $u['client_id'] === $client['id'] && $u['id'] !== ($user['id'] ?? null) && $this->isType($u, self::CLIENT_TYPE));
                $this->importMerchant($company, $client, $user, $others->values()->all());
            }

            $candidates = [];
            foreach ($livreurs as $courier) {
                $user = $this->accountOf($users, $courier['user_id'], 'livreur_id', $courier['id']);
                if ($user !== null && ! $this->isType($user, self::COURIER_TYPE)) {
                    $this->skip('livreur', $courier['id'], $this->clean($courier['nom']), 'rattaché à un compte de l\'équipe (type « '.($this->clean($user['usertype']) ?? 'aucun').' ») : non repris');

                    continue;
                }
                $candidates[] = [$courier, $user];
            }

            foreach ($this->mostRecentPerPhone($candidates) as [$courier, $user]) {
                $this->importCourier($company, $courier, $user);
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            Storage::disk('local')->delete($this->storedFiles);

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
            $this->skip('client', $client['id'], $name, 'numéro absent ou invalide : « '.($client['phone'] ?? '').' »');

            return;
        }

        if (User::takenIn($company->id, 'phone', $phone) || Merchant::forCompany($company->id)->where('phone', $phone)->exists()) {
            $this->skip('client', $client['id'], $name, 'numéro '.PhoneNumber::display($phone).' déjà présent (déjà importé ou déjà inscrit)');

            return;
        }

        $email = $this->email($company, $user['email'] ?? null);
        $zone = $this->zone($client['city']) ?? $this->zone($client['adresse']);
        [$lat, $lng] = $this->position($client['latitude'], $client['longitude']);

        if ($zone === null) {
            $this->warn('client', $client['id'], $name, 'commune « '.($client['city'] ?: $client['adresse']).' » non reconnue : zone de ramassage à choisir');
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
        ], Role::MerchantOwner, 'client', $client['id'], $merchant->business_name);

        $this->counts['merchants']++;

        foreach ($others as $other) {
            $this->importMerchantUser($company, $merchant, $other);
        }
    }

    /**
     * Autre compte de la boutique (employé) : même accès à l'espace client que dans l'ancienne application.
     *
     * @param  array<string, string|null>  $user
     */
    private function importMerchantUser(Company $company, Merchant $merchant, array $user): void
    {
        $name = $this->clean($user['name'] ?? null);
        $phone = PhoneNumber::normalize($user['phone'] ?? null);

        if ($phone === null) {
            $this->skip('compte client', $user['id'], $name, 'numéro absent ou invalide : « '.($user['phone'] ?? '').' »');

            return;
        }
        if (User::takenIn($company->id, 'phone', $phone)) {
            $this->skip('compte client', $user['id'], $name, 'numéro '.PhoneNumber::display($phone).' déjà présent (déjà importé ou compte existant)');

            return;
        }

        $this->account($company, $user, [
            'merchant_id' => $merchant->id,
            'name' => $name ?: 'Compte '.PhoneNumber::display($phone),
            'phone' => $phone,
            'email' => $this->email($company, $user['email'] ?? null),
            'status' => UserStatus::Active,
        ], Role::MerchantStaff, 'compte client', $user['id'], $name);

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

        // Livreur inactif dans l'ancienne application : compte suspendu
        $active = ($courier['status'] ?? 'active') === 'active';

        $account = $this->account($company, $user, [
            'name' => $name ?: 'Livreur '.PhoneNumber::display($phone),
            'phone' => $phone,
            'email' => $this->email($company, $user['email'] ?? null),
            'status' => $active ? UserStatus::Active : UserStatus::Suspended,
        ], Role::Courier, 'livreur', $courier['id'], $name);

        $account->syncCourierProfile();
        $certified = $this->date($courier['certified_at']);
        $notes = collect([
            $this->clean($courier['city']) ? 'Commune : '.$this->clean($courier['city']) : null,
            $this->clean($courier['adresse']) && $this->clean($courier['adresse']) !== $this->clean($courier['phone']) ? 'Adresse : '.$this->clean($courier['adresse']) : null,
            $certified ? 'Certifié le '.$certified->format('d/m/Y') : null,
        ])->filter()->implode(' · ');

        $profile = $account->courier()->withoutGlobalScopes()->first();
        if ($profile !== null) {
            $profile->update(['is_available' => false, 'notes' => $notes ?: null, 'id_number' => $this->idNumber($courier['pieces'])]);
            $profile->forceFill([
                'photo_path' => $this->legacyFile($profile->id, $courier['photo'], 'photo', $courier['id'], $name),
                'id_document_path' => $this->legacyFile($profile->id, $courier['piece_link'], 'pièce d\'identité', $courier['id'], $name),
            ])->save();
        }

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
     * Une seule fiche par numéro : la plus récemment utilisée (dernière position envoyée par l'application,
     * sinon dernière mise à jour, sinon la plus récente). Les autres sont listées dans le rapport.
     *
     * @param  list<array{0: array<string, string|null>, 1: array<string, string|null>|null}>  $candidates
     * @return list<array{0: array<string, string|null>, 1: array<string, string|null>|null}>
     */
    private function mostRecentPerPhone(array $candidates): array
    {
        $phoneOf = fn (array $c) => PhoneNumber::normalize($c[1]['phone'] ?? null) ?? PhoneNumber::normalize($c[0]['phone']);
        $recency = fn (array $c) => [
            $this->date($c[0]['geotime'])?->getTimestamp() ?? 0,
            $this->date($c[0]['updated_at'])?->getTimestamp() ?? 0,
            (int) $c[0]['id'],
        ];

        $kept = [];
        foreach ($candidates as $index => $candidate) {
            $phone = $phoneOf($candidate);
            // Numéro invalide : la fiche passe, l'import la refuse avec la raison
            if ($phone === null) {
                $kept["#{$index}"] = $candidate;

                continue;
            }
            $current = $kept[$phone] ?? null;
            if ($current === null) {
                $kept[$phone] = $candidate;

                continue;
            }
            [$winner, $loser] = $recency($candidate) > $recency($current) ? [$candidate, $current] : [$current, $candidate];
            $kept[$phone] = $winner;
            $this->skip('livreur', $loser[0]['id'], $this->clean($loser[0]['nom']) ?: $this->clean($loser[1]['name'] ?? null),
                'même numéro '.PhoneNumber::display($phone).' que la fiche n° '.$winner[0]['id'].' ('.($this->clean($winner[0]['nom']) ?: $this->clean($winner[1]['name'] ?? null)).'), plus récemment utilisée');
        }

        // Ordre de l'export conservé
        $order = array_flip(array_map(fn (array $c) => $c[0]['id'], $candidates));

        return collect($kept)->sortBy(fn (array $c) => $order[$c[0]['id']])->values()->all();
    }

    /** Numéro de pièce d'identité ; vide ou que des zéros (rempli pour la forme) : aucun */
    private function idNumber(?string $value): ?string
    {
        $value = $this->clean($value);

        return $value === null || preg_match('/^0+$/', $value) ? null : mb_substr($value, 0, 60);
    }

    /**
     * Copie une image de l'ancienne application (chemin relatif à son « storage/app/public ») dans le
     * dossier privé du livreur. Sans dossier des fichiers : rien. Simulation : vérifiée, pas copiée.
     */
    private function legacyFile(int $courierId, ?string $relative, string $label, ?string $legacyId, ?string $name): ?string
    {
        $relative = $this->clean($relative);
        if ($this->filesDir === null || $relative === null) {
            return null;
        }

        $source = realpath($this->filesDir.DIRECTORY_SEPARATOR.ltrim($relative, '/\\'));
        $extension = mb_strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        // Seulement une image, et seulement dans le dossier donné
        if ($source === false || ! str_starts_with($source, $this->filesDir.DIRECTORY_SEPARATOR) || ! is_file($source)
            || ! in_array($extension, self::IMAGE_EXTENSIONS, true) || @getimagesize($source) === false) {
            $this->warn('livreur', $legacyId, $name, $label.' introuvable ou illisible : « '.$relative.' »');

            return null;
        }

        $this->counts[$label === 'photo' ? 'courier_photos' : 'courier_id_documents']++;
        if ($this->dryRun) {
            return null;
        }

        $path = "couriers/{$courierId}/".Str::random(40).'.'.($extension === 'jpeg' ? 'jpg' : $extension);
        Storage::disk('local')->put($path, file_get_contents($source));
        $this->storedFiles[] = $path;

        return $path;
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
