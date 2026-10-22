<?php

namespace App\Services\Messaging;

use App\Enums\WhatsAppTemplate;
use App\Models\Company;
use App\Models\WhatsAppAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Branchement d'un numéro WhatsApp Business (API Cloud de Meta) :
 *  - vérifie le numéro et le jeton (nom affiché, note de qualité) ;
 *  - abonne l'application Meta de la plateforme au compte WhatsApp, pour recevoir
 *    les messages et les accusés de réception ;
 *  - soumet les modèles de messages à Meta et relève leur état ;
 *  - bouton « Connecter WhatsApp » (inscription intégrée de Meta) : tout cela en un clic.
 * Une connexion réussie fait passer l'entreprise en envois réels (WHATSAPP_DRIVER=auto).
 */
class WhatsAppConnection
{
    // Modèle déjà existant chez Meta (même nom et même langue) : rien à faire
    private const TEMPLATE_EXISTS_SUBCODES = [2388023, 2388024];

    /**
     * Bouton « Connecter WhatsApp » disponible : application Meta et configuration
     * « Facebook Login for Business » renseignées sur le serveur.
     */
    public static function signupAvailable(): bool
    {
        return filled(config('messaging.whatsapp.app_id'))
            && filled(config('messaging.whatsapp.signup_config_id'))
            && filled(config('messaging.whatsapp.app_secret'));
    }

    /**
     * Fin de l'inscription intégrée de Meta : l'entreprise a autorisé Jibiat sur son compte
     * WhatsApp Business et choisi son numéro. Le code d'autorisation est échangé contre un
     * jeton, le numéro est activé pour l'API Cloud, vérifié, abonné aux messages reçus, et
     * les modèles de messages sont soumis à Meta.
     */
    public function completeSignup(Company $company, string $code, string $phoneNumberId, string $wabaId): WhatsAppAccount
    {
        if (! self::signupAvailable()) {
            throw new MessagingException('Le bouton « Connecter WhatsApp » n\'est pas encore configuré sur ce serveur.');
        }

        $token = $this->exchangeCode($code);
        $expiresAt = $this->tokenExpiry($token);

        $account = WhatsAppAccount::forCompanyId($company->id)
            ?? new WhatsAppAccount(['company_id' => $company->id, 'owner_type' => 'company']);

        // Nouveau numéro : nouveau code PIN d'activation
        if ($account->phone_number_id !== $phoneNumberId || blank($account->registration_pin)) {
            $account->registration_pin = (string) random_int(100000, 999999);
        }

        $account->fill([
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $wabaId,
            'status' => 'pending',
            'last_error' => null,
            'connected_via' => 'embedded_signup',
            'token_expires_at' => $expiresAt,
            'token_warned_at' => null,
            'platform_token' => false,
        ]);
        $account->access_token = $token;
        $account->save();

        // Jeton de l'entreprise limité dans le temps : la plateforme prend le relais si elle le peut
        $this->adoptWithPlatform($account);

        $registration = $this->register($account);
        $this->connect($account);

        if ($account->status !== 'connected') {
            return $account;
        }

        if ($registration !== null) {
            $account->forceFill(['last_error' => 'Numéro connecté, mais son activation a échoué : '.$registration])->save();
        }

        try {
            $this->submitTemplates($account);
        } catch (MessagingException) {
            // Les modèles pourront être soumis depuis la page WhatsApp
        }

        return $account;
    }

    /**
     * Vérifie le numéro auprès de Meta et met à jour son état ; renvoie le compte.
     */
    public function connect(WhatsAppAccount $account): WhatsAppAccount
    {
        if (! $account->isConfigured()) {
            $account->forceFill(['status' => 'pending', 'last_error' => null])->save();

            return $account;
        }

        try {
            $info = $this->call(fn () => $this->client($account)->get($account->phone_number_id, [
                'fields' => 'display_phone_number,verified_name,quality_rating',
            ]));
        } catch (MessagingException $e) {
            $account->forceFill(['status' => 'error', 'last_error' => $this->explain($e)])->save();

            return $account;
        }

        $account->forceFill([
            'status' => 'connected',
            'last_error' => null,
            'display_phone' => $info->json('display_phone_number') ? preg_replace('/[^\d+]/', '', $info->json('display_phone_number')) : $account->display_phone,
            'verified_name' => $info->json('verified_name'),
            'quality_rating' => $info->json('quality_rating') ? strtolower($info->json('quality_rating')) : null,
            'connected_at' => $account->connected_at ?? now(),
        ])->save();

        $this->subscribe($account);

        return $account;
    }

    /**
     * Échange le code d'autorisation de l'inscription intégrée contre un jeton d'accès
     * au compte WhatsApp Business de l'entreprise.
     */
    private function exchangeCode(string $code): string
    {
        $base = rtrim(config('messaging.whatsapp.graph_url'), '/').'/'.config('messaging.whatsapp.graph_version');

        try {
            $response = $this->call(fn () => Http::baseUrl($base)->acceptJson()->timeout(15)->get('oauth/access_token', [
                'client_id' => config('messaging.whatsapp.app_id'),
                'client_secret' => config('messaging.whatsapp.app_secret'),
                'code' => $code,
            ]));
        } catch (MessagingException $e) {
            // Code expiré (quelques minutes), déjà utilisé ou application mal configurée
            throw new MessagingException('Autorisation de Meta refusée ou expirée : recommencez la connexion. ('.$e->getMessage().')');
        }

        $token = $response?->json('access_token');
        if (blank($token)) {
            throw new MessagingException('Meta n\'a pas renvoyé d\'accès au compte WhatsApp. Recommencez la connexion.');
        }

        return $token;
    }

    /**
     * Date d'expiration du jeton remis par Meta (null : il n'expire pas). En cas de doute,
     * 60 jours, la durée des configurations « WhatsApp Embedded Signup » de Meta.
     */
    private function tokenExpiry(string $token): ?Carbon
    {
        try {
            $response = $this->call(fn () => $this->http(config('messaging.whatsapp.app_id').'|'.config('messaging.whatsapp.app_secret'))
                ->get('debug_token', ['input_token' => $token]));
        } catch (MessagingException) {
            return now()->addDays(60);
        }

        $expires = $response?->json('data.expires_at');

        return match (true) {
            $expires === 0 => null,
            is_int($expires) && $expires > 0 => Carbon::createFromTimestamp($expires),
            default => now()->addDays(60),
        };
    }

    /**
     * Ajoute l'utilisateur système de Jibiat (jeton permanent) au compte WhatsApp Business de
     * l'entreprise, partagé avec la plateforme lors du bouton « Connecter WhatsApp » : les envois
     * ne dépendent plus du jeton de l'entreprise et de son expiration. Renvoie false si ce n'est
     * pas possible (plateforme non configurée, droits manquants) : le jeton de l'entreprise reste utilisé.
     */
    public function adoptWithPlatform(WhatsAppAccount $account): bool
    {
        $userId = config('messaging.whatsapp.system_user_id');
        $token = config('messaging.whatsapp.system_user_token');

        if (blank($userId) || blank($token) || blank($account->waba_id) || blank($account->phone_number_id)) {
            return false;
        }

        try {
            $this->call(fn () => $this->http($token)->post("{$account->waba_id}/assigned_users", [
                'user' => $userId,
                'tasks' => ['MANAGE'],
            ]));
            // Le numéro répond avec le jeton de la plateforme
            $this->call(fn () => $this->http($token)->get($account->phone_number_id, ['fields' => 'id']));
        } catch (MessagingException) {
            return false;
        }

        $account->forceFill(['platform_token' => true, 'token_warned_at' => null])->save();

        return true;
    }

    /**
     * Active le numéro pour l'API Cloud (avec son code PIN de vérification en deux étapes).
     * Renvoie null si c'est fait, sinon le message d'erreur.
     */
    private function register(WhatsAppAccount $account): ?string
    {
        try {
            $this->call(fn () => $this->client($account)->post("{$account->phone_number_id}/register", [
                'messaging_product' => 'whatsapp',
                'pin' => $account->registration_pin,
            ]));

            return null;
        } catch (MessagingException $e) {
            return $this->explain($e);
        }
    }

    /**
     * Abonne l'application Meta au compte WhatsApp Business (messages reçus, accusés).
     */
    public function subscribe(WhatsAppAccount $account): bool
    {
        if (blank($account->waba_id)) {
            return false;
        }

        try {
            $this->call(fn () => $this->client($account)->post("{$account->waba_id}/subscribed_apps"));
        } catch (MessagingException $e) {
            $account->forceFill(['last_error' => 'Numéro connecté, mais abonnement aux messages reçus impossible : '.$this->explain($e)])->save();

            return false;
        }

        $account->forceFill(['webhook_subscribed_at' => now()])->save();

        return true;
    }

    /**
     * Soumet à Meta les modèles du catalogue qui ne sont ni approuvés ni en cours de
     * validation. Renvoie, par modèle soumis, « ok » ou le message d'erreur.
     *
     * @return array<string, string>
     */
    public function submitTemplates(WhatsAppAccount $account): array
    {
        if (! $account->isConfigured() || blank($account->waba_id)) {
            throw new MessagingException('Renseignez et connectez d\'abord le numéro, avec l\'identifiant du compte WhatsApp Business (WABA ID).');
        }

        $known = $account->templates()->where('language', WhatsAppTemplate::LANGUAGE)->pluck('status', 'name');
        $results = [];

        foreach (WhatsAppTemplate::cases() as $template) {
            if (in_array($known[$template->value] ?? null, ['approved', 'pending'], true)) {
                continue;
            }

            try {
                $response = $this->call(fn () => $this->client($account)->post("{$account->waba_id}/message_templates", [
                    'name' => $template->value,
                    'language' => WhatsAppTemplate::LANGUAGE,
                    'category' => 'UTILITY',
                    'components' => [[
                        'type' => 'BODY',
                        'text' => $template->body(),
                        'example' => ['body_text' => [$template->example()]],
                    ]],
                ]), allowExisting: true);

                $status = $response ? strtolower($response->json('status') ?? 'pending') : 'pending';
                $account->templates()->updateOrCreate(
                    ['name' => $template->value, 'language' => WhatsAppTemplate::LANGUAGE],
                    ['status' => $status, 'category' => 'utility', 'rejected_reason' => null, 'synced_at' => now()],
                );
                $results[$template->value] = 'ok';
            } catch (MessagingException $e) {
                $results[$template->value] = $this->explain($e);
            }
        }

        return $results;
    }

    /**
     * Message compréhensible pour les erreurs Meta les plus courantes.
     */
    private function explain(MessagingException $e): string
    {
        return match ($e->providerCode) {
            '190' => 'Jeton d\'accès invalide ou expiré. Créez un jeton permanent (utilisateur système) dans Meta Business.',
            '100' => 'Identifiant introuvable ou inaccessible avec ce jeton. Vérifiez l\'identifiant du numéro et du compte WhatsApp Business.',
            '10', '200' => 'Ce jeton n\'a pas les autorisations WhatsApp nécessaires (whatsapp_business_management, whatsapp_business_messaging).',
            default => $e->getMessage(),
        };
    }

    private function client(WhatsAppAccount $account): PendingRequest
    {
        return $this->http($account->apiToken());
    }

    private function http(?string $token): PendingRequest
    {
        $base = rtrim(config('messaging.whatsapp.graph_url'), '/').'/'.config('messaging.whatsapp.graph_version');

        return Http::baseUrl($base)->withToken((string) $token)->acceptJson()->timeout(15);
    }

    /**
     * @param  callable(): Response  $request
     */
    private function call(callable $request, bool $allowExisting = false): ?Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new MessagingException('Meta injoignable : '.$e->getMessage(), retryable: true);
        } catch (RequestException $e) {
            // Réponse d'erreur levée en exception (proxy, client configuré pour lever) : traitée comme les autres
            $response = $e->response;
        }

        if ($response->successful()) {
            return $response;
        }

        if ($allowExisting && in_array($response->json('error.error_subcode'), self::TEMPLATE_EXISTS_SUBCODES, true)) {
            return null;
        }

        $code = $response->json('error.code');

        throw new MessagingException(
            'Meta : '.($response->json('error.error_user_msg') ?? $response->json('error.message') ?? 'erreur HTTP '.$response->status()),
            providerCode: $code !== null ? (string) $code : null,
        );
    }
}
