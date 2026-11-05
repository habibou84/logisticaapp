<?php

namespace App\Services\Accounts;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\Messaging\Gateways\SmsGateway;
use App\Services\Messaging\MessagingException;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Vérification d'un numéro de téléphone, pour l'inscription d'un client ou
 * un mot de passe oublié :
 *  - WhatsApp (gratuit) : la personne envoie depuis son WhatsApp un message prérempli
 *    contenant un code au numéro de l'entreprise ; l'expéditeur prouve le numéro ;
 *  - SMS (secours) : un code secret est envoyé au numéro et saisi sur la page.
 */
class PhoneVerifier
{
    public const TTL_MINUTES = 30;

    public const MAX_ATTEMPTS = 5;

    public const MAX_SMS = 3;

    public const SMS_COOLDOWN_SECONDS = 60;

    // Par numéro et par heure : demandes de vérification (par adresse IP, pour qu'un inconnu ne bloque pas le titulaire,
    // et toutes adresses confondues, plus large), SMS envoyés
    public const MAX_STARTS_PER_HOUR = 5;

    public const MAX_STARTS_PER_HOUR_ALL_IPS = 20;

    public const MAX_SMS_PER_HOUR = 3;

    // Par numéro et par heure, toutes demandes confondues : codes faux saisis
    public const MAX_WRONG_PER_HOUR = 10;

    // Mot de passe oublié par WhatsApp : délai pour répondre « OUI » après le message du code
    public const CONFIRM_MINUTES = 10;

    // Début du message WhatsApp prérempli, reconnu à la réception
    public const WHATSAPP_PREFIX = 'Code de vérification';

    public function __construct(private readonly SmsGateway $sms) {}

    /**
     * Ouvre une vérification. Renvoie la vérification et le jeton à garder côté navigateur.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array{0: PhoneVerification, 1: string}
     */
    public function start(Company $company, string $purpose, string $phone, ?User $user = null, ?array $payload = null, ?string $ip = null): array
    {
        $phone = PhoneNumber::normalize($phone) ?? $phone;

        $recent = PhoneVerification::where('phone', $phone)->where('created_at', '>', now()->subHour());
        $fromIp = (clone $recent)->when($ip !== null, fn ($q) => $q->where('ip', $ip), fn ($q) => $q->whereNull('ip'))->count();
        if ($fromIp >= self::MAX_STARTS_PER_HOUR || $recent->count() >= self::MAX_STARTS_PER_HOUR_ALL_IPS) {
            throw new BusinessRuleException('Trop de demandes pour ce numéro. Réessayez dans une heure.', 'phone');
        }

        $token = Str::random(48);

        $verification = DB::transaction(function () use ($company, $purpose, $phone, $user, $payload, $ip, $token) {
            // Une seule demande en cours par numéro et par usage
            PhoneVerification::where('company_id', $company->id)->where('purpose', $purpose)->where('phone', $phone)
                ->pending()->update(['expires_at' => now()]);

            return PhoneVerification::create([
                'company_id' => $company->id,
                'purpose' => $purpose,
                'phone' => $phone,
                'token_hash' => PhoneVerification::hashToken($token),
                'whatsapp_code' => $this->uniqueWhatsAppCode($company),
                'user_id' => $user?->id,
                'payload' => $payload,
                'ip' => $ip,
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);
        });

        return [$verification, $token];
    }

    public function find(string $token): ?PhoneVerification
    {
        return PhoneVerification::where('token_hash', PhoneVerification::hashToken($token))->first();
    }

    /**
     * Numéro WhatsApp de l'entreprise auquel envoyer le message de vérification
     * (null si WhatsApp n'est pas branché à l'API Cloud de Meta).
     */
    public function whatsappNumber(Company $company): ?string
    {
        $account = WhatsAppAccount::forCompanyId($company->id);

        return $account?->isLive() && filled($account->display_phone)
            ? PhoneNumber::normalize($account->display_phone)
            : null;
    }

    /**
     * SMS possible vers ce numéro : envoi configuré et mobile du pays (les numéros
     * étrangers sont refusés pour couper court à la fraude aux SMS surtaxés).
     */
    public function smsAvailable(string $phone): bool
    {
        return config('messaging.sms.driver') !== 'none' && PhoneNumber::isLocalMobile($phone);
    }

    /**
     * SMS simulés (démonstration) : le code SMS est affiché sur la page, sinon personne
     * ne pourrait le lire. Jamais en production : le code renvoyé par l'API ouvrirait
     * n'importe quel compte avec son seul numéro.
     */
    public function demoMode(): bool
    {
        return ! app()->isProduction()
            && config('messaging.whatsapp.driver') !== 'meta' && config('messaging.sms.driver') === 'log';
    }

    /**
     * Envoie un code par SMS. Renvoie le code en mode démonstration, null sinon.
     */
    public function sendSms(PhoneVerification $verification): ?string
    {
        $this->ensureOpen($verification);

        if (! $this->smsAvailable($verification->phone)) {
            throw new BusinessRuleException("L'envoi de SMS n'est pas possible vers ce numéro. Utilisez la vérification par WhatsApp.");
        }

        $wait = $this->smsWaitSeconds($verification);
        if ($wait > 0) {
            throw new BusinessRuleException("Patientez {$wait} secondes avant de demander un nouveau code.");
        }

        if ($verification->sms_count >= self::MAX_SMS
            || PhoneVerification::where('phone', $verification->phone)->where('sms_sent_at', '>', now()->subHour())->sum('sms_count') >= self::MAX_SMS_PER_HOUR) {
            throw new BusinessRuleException('Nombre maximal de SMS atteint pour ce numéro. Réessayez dans une heure ou vérifiez par WhatsApp.');
        }

        $code = (string) random_int(100000, 999999);
        $verification->forceFill([
            'sms_code_hash' => Hash::make($code),
            'sms_count' => $verification->sms_count + 1,
            'sms_sent_at' => now(),
        ])->save();

        // Mot de passe oublié pour un numéro inconnu : rien n'est envoyé, sans le révéler
        if ($verification->purpose === PhoneVerification::PASSWORD_RESET && $verification->user_id === null) {
            return null;
        }

        // Envoi direct (hors journal des messages : le code ne doit pas y apparaître)
        try {
            $this->sms->send($verification->phone, $this->smsText($verification, $code));
        } catch (MessagingException $e) {
            Log::warning('SMS de vérification non envoyé', ['phone' => $verification->phone, 'error' => $e->getMessage()]);
            $verification->forceFill(['sms_count' => $verification->sms_count - 1, 'sms_sent_at' => null])->save();

            throw new BusinessRuleException("Le SMS n'a pas pu être envoyé. Réessayez dans un instant ou vérifiez par WhatsApp.");
        }

        return $this->demoMode() ? $code : null;
    }

    public function smsWaitSeconds(PhoneVerification $verification): int
    {
        if ($verification->sms_sent_at === null) {
            return 0;
        }

        return max(0, self::SMS_COOLDOWN_SECONDS - (int) $verification->sms_sent_at->diffInSeconds(now()));
    }

    /**
     * Code reçu par SMS, saisi sur la page.
     */
    public function checkSmsCode(PhoneVerification $verification, string $code): void
    {
        $this->ensureOpen($verification);

        if ($verification->isVerified()) {
            return;
        }

        if ($verification->sms_code_hash === null) {
            throw new BusinessRuleException("Demandez d'abord un code par SMS.", 'code');
        }

        // Codes faux pour ce numéro dans l'heure, toutes demandes confondues : on ne devine pas un code en
        // multipliant les demandes
        $wrong = (int) PhoneVerification::where('phone', $verification->phone)->where('created_at', '>', now()->subHour())->whereNull('verified_at')->sum('attempts');
        if ($wrong >= self::MAX_WRONG_PER_HOUR) {
            throw new BusinessRuleException('Trop de codes faux pour ce numéro. Réessayez dans une heure.', 'code');
        }

        // Essai compté d'un seul coup en base : des requêtes simultanées ne passent pas toutes la limite
        $counted = PhoneVerification::whereKey($verification->id)->where('attempts', '<', self::MAX_ATTEMPTS)->increment('attempts');
        if ($counted === 0) {
            throw new BusinessRuleException('Trop d\'essais. Recommencez la demande depuis le début.', 'code');
        }
        $verification->refresh();

        if (! Hash::check(preg_replace('/\D+/', '', $code), $verification->sms_code_hash)) {
            $left = self::MAX_ATTEMPTS - $verification->attempts;
            throw new BusinessRuleException($left > 0 ? "Code incorrect. Encore {$left} essai(s)." : 'Code incorrect. Recommencez la demande depuis le début.', 'code');
        }

        $this->markVerified($verification, 'sms');
    }

    /**
     * Message WhatsApp reçu : s'il porte un code de vérification, valide le numéro et
     * renvoie la réponse à faire ; null si le message ne concerne pas une vérification.
     */
    public function handleWhatsApp(Company $company, string $from, ?string $text): ?string
    {
        if (self::isYes($text)) {
            return $this->confirm($company, PhoneVerification::where('company_id', $company->id), $from);
        }

        if (! preg_match('/code\s+de\s+v[ée]rification\D{0,10}(\d{6})/iu', (string) $text, $m)) {
            return null;
        }

        $verification = PhoneVerification::where('company_id', $company->id)->where('whatsapp_code', $m[1])
            ->pending()->latest('id')->first();

        // Codes essayés au hasard depuis un même numéro : 10 par heure au plus (code inconnu ou d'un autre numéro)
        $misses = 'wa-code-miss:'.hash('sha256', (string) PhoneNumber::normalize($from));
        if ($verification === null || $verification->phone !== PhoneNumber::normalize($from)) {
            Cache::add($misses, 0, now()->addHour());
            if ((int) Cache::increment($misses) > self::MAX_WRONG_PER_HOUR) {
                return 'Trop de codes non reconnus depuis ce numéro. Réessayez dans une heure.';
            }
        }

        if ($verification === null) {
            return "Ce code n'est plus valable (il expire au bout de ".self::TTL_MINUTES.' minutes). Recommencez depuis la page du site.';
        }

        if ($verification->phone !== PhoneNumber::normalize($from)) {
            // Jamais le numéro lié au code : il ne se devine pas en envoyant des codes au hasard
            return 'Ce code a été demandé pour un autre numéro que celui de ce WhatsApp. '
                .'Envoyez le message depuis le WhatsApp du numéro saisi sur le site.';
        }

        // Mot de passe oublié : le message a pu être envoyé à la demande d'un escroc (« envoyez ce message pour
        // confirmer votre livraison »). On demande une confirmation explicite avant d'ouvrir l'accès.
        if ($verification->purpose === PhoneVerification::PASSWORD_RESET && $verification->user_id !== null) {
            $verification->forceFill(['payload' => [...($verification->payload ?? []), 'confirm_asked_at' => now()->toIso8601String()]])->save();

            return "⚠️ Quelqu'un demande à changer le mot de passe de votre compte {$company->name} depuis le site."
                ."\n\nSi c'est bien vous, répondez *OUI* dans les ".self::CONFIRM_MINUTES.' minutes.'
                ."\n\nSi ce n'est pas vous, ne répondez pas : personne n'entrera dans votre compte. {$company->name} ne vous demandera jamais d'envoyer ce message pour une livraison ou un paiement.";
        }

        return $this->verifiedAnswer($company, $verification);
    }

    /**
     * « OUI » après le message d'un code de mot de passe oublié : la demande en attente de ce numéro est validée.
     * Null si rien n'attend de confirmation (le message suit son cours normal).
     *
     * @param  Builder<PhoneVerification>  $scope
     */
    private function confirm(Company $company, Builder $scope, string $from): ?string
    {
        $verification = $scope->pending()->where('purpose', PhoneVerification::PASSWORD_RESET)
            ->where('created_at', '>', now()->subMinutes(self::TTL_MINUTES))->latest('id')->limit(20)->get()
            ->first(fn (PhoneVerification $v) => $v->phone === PhoneNumber::normalize($from)
                && isset($v->payload['confirm_asked_at'])
                && Carbon::parse($v->payload['confirm_asked_at'])->gt(now()->subMinutes(self::CONFIRM_MINUTES)));

        return $verification ? $this->verifiedAnswer($company, $verification) : null;
    }

    /** « oui », « OUI », « Oui ! » */
    public static function isYes(?string $text): bool
    {
        return (bool) preg_match('/^\s*oui\W*$/iu', (string) $text);
    }

    private function verifiedAnswer(Company $company, PhoneVerification $verification): string
    {
        try {
            $this->markVerified($verification, 'whatsapp');
        } catch (BusinessRuleException $e) {
            return $e->getMessage();
        }

        return match ($verification->purpose) {
            PhoneVerification::SIGNUP => "✅ Numéro vérifié ! Bienvenue chez {$company->name}. Votre compte « ".($verification->payload['business_name'] ?? '')
                .' » est ouvert : retournez sur la page du site, vous y êtes connecté. Vous pouvez aussi créer vos courses ici en tapant « menu ».',
            default => $verification->user_id
                ? '✅ Numéro vérifié. Retournez sur la page du site pour choisir votre nouveau mot de passe.'
                : "Aucun compte n'est associé à ce numéro chez {$company->name}.",
        };
    }

    private function markVerified(PhoneVerification $verification, string $via): void
    {
        if (! $verification->isVerified()) {
            $verification->forceFill(['verified_at' => now(), 'verified_via' => $via])->save();
        }

        if ($verification->purpose === PhoneVerification::SIGNUP && $verification->user_id === null) {
            app(MerchantSignup::class)->complete($verification);
        }
    }

    private function ensureOpen(PhoneVerification $verification): void
    {
        if ($verification->completed_at !== null || ($verification->isExpired() && ! $verification->isVerified())) {
            throw new BusinessRuleException('Cette demande a expiré. Recommencez.');
        }
    }

    private function smsText(PhoneVerification $verification, string $code): string
    {
        $company = $verification->company;
        $what = $verification->purpose === PhoneVerification::SIGNUP ? 'pour créer votre compte' : 'pour changer votre mot de passe';

        return "{$code} est votre code {$company->name} {$what}. Il expire dans ".self::TTL_MINUTES.' minutes. Ne le communiquez à personne.';
    }

    private function uniqueWhatsAppCode(Company $company): string
    {
        do {
            $code = (string) random_int(100000, 999999);
        } while (PhoneVerification::where('company_id', $company->id)->where('whatsapp_code', $code)->pending()->exists());

        return $code;
    }
}
