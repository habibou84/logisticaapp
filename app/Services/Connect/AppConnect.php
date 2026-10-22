<?php

namespace App\Services\Connect;

use App\Enums\MerchantStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\ConnectCode;
use App\Models\ConnectRequest;
use App\Models\Merchant;
use App\Models\User;
use App\Notifications\ConnectRequestReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Connexion en un clic d'une application (Jibiat Marchand) au compte d'un e-commerçant, sur le modèle
 * d'OAuth 2 avec PKCE : l'e-commerçant autorise l'accès, l'application reçoit un code à usage unique
 * qu'elle échange, avec son code_verifier, contre une clé d'API. Le marchand ne copie jamais de clé.
 */
class AppConnect
{
    /**
     * Application et adresse de retour vérifiées.
     *
     * @return array{id: string, name: string, redirect_uri: string, scopes: list<string>}
     */
    public function client(?string $clientId, ?string $redirectUri): array
    {
        $client = config('connect.clients.'.(string) $clientId);
        if (! is_array($client)) {
            throw new BusinessRuleException('Application inconnue.', 'client_id');
        }

        if (! $this->redirectAllowed((string) $redirectUri, $client['redirect_hosts'] ?? [])) {
            throw new BusinessRuleException('Adresse de retour non autorisée pour cette application.', 'redirect_uri');
        }

        return ['id' => (string) $clientId, 'name' => $client['name'], 'redirect_uri' => (string) $redirectUri, 'scopes' => $client['scopes']];
    }

    /**
     * @param  list<string>  $hosts
     */
    private function redirectAllowed(string $uri, array $hosts): bool
    {
        $parts = parse_url($uri);
        $host = mb_strtolower($parts['host'] ?? '');
        $scheme = $parts['scheme'] ?? '';
        if ($host === '' || isset($parts['user']) || ! in_array($scheme, ['https', 'http'], true)) {
            return false;
        }
        // http seulement pour un poste de développement
        if ($scheme === 'http' && ! in_array($host, ['localhost', '127.0.0.1'], true) && ! str_ends_with($host, '.localhost')) {
            return false;
        }

        foreach ($hosts as $allowed) {
            $allowed = mb_strtolower($allowed);
            if ($host === $allowed || (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * L'e-commerçant (gérant) autorise l'application : adresse de retour avec le code.
     */
    public function authorize(User $user, string $clientId, string $redirectUri, string $state, string $challenge, string $method = 'S256'): string
    {
        $client = $this->client($clientId, $redirectUri);

        if ($method !== 'S256' || ! preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge)) {
            throw new BusinessRuleException('Demande de connexion invalide (défi PKCE).', 'code_challenge');
        }

        $merchant = $user->merchant_id ? Merchant::withoutGlobalScopes()->find($user->merchant_id) : null;
        if ($merchant === null || ! $user->hasRole(Role::MerchantOwner->value)) {
            throw new BusinessRuleException('Seul le gérant d\'une boutique peut connecter une application.');
        }
        if ($merchant->status !== MerchantStatus::Active) {
            throw new BusinessRuleException('Votre compte est suspendu : contactez votre entreprise de livraison.');
        }

        $code = Str::random(48);
        ConnectCode::create([
            'company_id' => $merchant->company_id,
            'merchant_id' => $merchant->id,
            'user_id' => $user->id,
            'client_id' => $client['id'],
            'redirect_uri' => $client['redirect_uri'],
            'code_hash' => hash('sha256', $code),
            'code_challenge' => $challenge,
            'expires_at' => now()->addSeconds((int) config('connect.code_ttl', 600)),
        ]);

        return self::withQuery($client['redirect_uri'], ['code' => $code, 'state' => $state]);
    }

    /**
     * Échange du code (une seule fois, avant expiration, avec le bon code_verifier) contre une clé d'API.
     *
     * @return array{0: ApiKey, 1: string, 2: Merchant}
     */
    public function exchange(Company $company, string $clientId, string $code, string $verifier, string $redirectUri): array
    {
        $hash = hash('sha256', $code);
        $result = DB::transaction(function () use ($company, $clientId, $hash, $verifier, $redirectUri) {
            $grant = ConnectCode::where('code_hash', $hash)->lockForUpdate()->first();

            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $valid = $grant !== null
                && $grant->company_id === $company->id
                && $grant->used_at === null
                && $grant->expires_at->isFuture()
                && $grant->client_id === $clientId
                && $grant->redirect_uri === $redirectUri
                && hash_equals($grant->code_challenge, $challenge);

            if (! $valid) {
                return null;
            }

            $grant->forceFill(['used_at' => now()])->save();
            $merchant = $grant->merchant;
            if ($merchant === null || $merchant->status !== MerchantStatus::Active) {
                throw new ConnectException('invalid_grant', 'Compte de l\'e-commerçant indisponible.');
            }

            $client = config("connect.clients.{$clientId}");
            [$key, $plain] = ApiKey::issue($merchant, $client['name'], $client['scopes'], $grant->user, client: $clientId);

            return [$key, $plain, $merchant];
        });

        if ($result === null) {
            // Code faux, expiré ou rejoué : par prudence, il ne servira plus jamais
            ConnectCode::where('code_hash', $hash)->whereNull('used_at')->update(['used_at' => now()]);

            throw new ConnectException('invalid_grant', 'Code de connexion invalide ou expiré : recommencez la connexion.');
        }

        return $result;
    }

    /**
     * Demande d'ouverture de compte (inscriptions fermées) : l'équipe la valide ou la refuse.
     *
     * @param  array<string, mixed>  $data
     */
    public function request(Company $company, array $data, ?string $clientId): ConnectRequest
    {
        $taken = User::takenIn($company->id, 'phone', $data['phone'])
            || Merchant::forCompany($company->id)->where('phone', $data['phone'])->exists();
        if ($taken) {
            throw new BusinessRuleException('Ce numéro a déjà un compte chez cette entreprise : connectez-vous.', 'phone');
        }

        $pending = ConnectRequest::forCompany($company->id)->where('phone', $data['phone'])->where('status', ConnectRequest::PENDING)->first();
        if ($pending) {
            return $pending;
        }

        $request = ConnectRequest::create([
            'company_id' => $company->id,
            'client_id' => $clientId,
            'business_name' => trim($data['business_name']),
            'contact_name' => trim($data['contact_name']),
            'phone' => $data['phone'],
            'email' => filled($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null,
            'pickup_zone_id' => $data['pickup_zone_id'] ?? null,
            'pickup_address' => filled($data['pickup_address'] ?? null) ? trim($data['pickup_address']) : null,
            'message' => filled($data['message'] ?? null) ? trim($data['message']) : null,
            'password_hash' => $data['password_hash'],
            'status' => ConnectRequest::PENDING,
        ]);

        $staff = User::forCompany($company->id)->where('status', 'active')->whereNull('merchant_id')
            ->permission(Permission::MerchantsManage->value)->get();
        if ($staff->isNotEmpty()) {
            Notification::send($staff, new ConnectRequestReceived($request));
        }

        return $request;
    }

    /**
     * Demande acceptée : la boutique et le compte du gérant sont créés avec le mot de passe qu'il a choisi.
     */
    public function accept(ConnectRequest $request, User $actor): Merchant
    {
        return DB::transaction(function () use ($request, $actor) {
            $request = ConnectRequest::forCompany($request->company_id)->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== ConnectRequest::PENDING) {
                throw new BusinessRuleException('Cette demande a déjà été traitée.');
            }
            if (User::takenIn($request->company_id, 'phone', $request->phone)
                || Merchant::forCompany($request->company_id)->where('phone', $request->phone)->exists()) {
                throw new BusinessRuleException('Ce numéro a déjà un compte : refusez la demande et donnez-lui ses accès.');
            }

            $merchant = Merchant::create([
                'company_id' => $request->company_id,
                'business_name' => $request->business_name,
                'contact_name' => $request->contact_name,
                'phone' => $request->phone,
                'email' => User::takenIn($request->company_id, 'email', $request->email) ? null : $request->email,
                'pickup_zone_id' => $request->pickup_zone_id,
                'pickup_address' => $request->pickup_address,
                'status' => MerchantStatus::Active,
            ]);
            $merchant->forceFill(['source' => 'connect'])->save();

            $user = new User([
                'company_id' => $request->company_id,
                'merchant_id' => $merchant->id,
                'name' => $request->contact_name,
                'phone' => $request->phone,
                'email' => $merchant->email,
            ]);
            $user->forceFill(['password' => $request->password_hash ?: bcrypt(Str::random(32))])->save();
            $user->assignRole(Role::MerchantOwner->value);

            $request->forceFill([
                'status' => ConnectRequest::ACCEPTED, 'merchant_id' => $merchant->id, 'decided_by' => $actor->id,
                'decided_at' => now(), 'password_hash' => null,
            ])->save();

            return $merchant;
        });
    }

    public function decline(ConnectRequest $request, User $actor): void
    {
        if ($request->status !== ConnectRequest::PENDING) {
            throw new BusinessRuleException('Cette demande a déjà été traitée.');
        }

        $request->forceFill(['status' => ConnectRequest::DECLINED, 'decided_by' => $actor->id, 'decided_at' => now(), 'password_hash' => null])->save();
    }

    /**
     * @param  array<string, string>  $params
     */
    public static function withQuery(string $uri, array $params): string
    {
        return $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($params);
    }
}
