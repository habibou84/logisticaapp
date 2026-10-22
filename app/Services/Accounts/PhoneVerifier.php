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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Vérification d'un numéro de téléphone, pour l'inscription d'un e-commerçant ou
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

    // Par numéro et par heure : demandes de vérification, SMS envoyés
    public const MAX_STARTS_PER_HOUR = 5;

    public const MAX_SMS_PER_HOUR = 3;

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

        $recent = PhoneVerification::where('phone', $phone)->where('created_at', '>', now()->subHour())->count();
        if ($recent >= self::MAX_STARTS_PER_HOUR) {
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
     * ne pourrait le lire.
     */
    public function demoMode(): bool
    {
        return config('messaging.whatsapp.driver') !== 'meta' && config('messaging.sms.driver') === 'log';
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
            'attempts' => 0,
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

        if ($verification->attempts >= self::MAX_ATTEMPTS) {
            throw new BusinessRuleException('Trop d\'essais. Demandez un nouveau code.', 'code');
        }

        $verification->increment('attempts');

        if (! Hash::check(preg_replace('/\D+/', '', $code), $verification->sms_code_hash)) {
            $left = self::MAX_ATTEMPTS - $verification->attempts;
            throw new BusinessRuleException($left > 0 ? "Code incorrect. Encore {$left} essai(s)." : 'Code incorrect. Demandez un nouveau code.', 'code');
        }

        $this->markVerified($verification, 'sms');
    }

    /**
     * Message WhatsApp reçu : s'il porte un code de vérification, valide le numéro et
     * renvoie la réponse à faire ; null si le message ne concerne pas une vérification.
     */
    public function handleWhatsApp(Company $company, string $from, ?string $text): ?string
    {
        if (! preg_match('/code\s+de\s+v[ée]rification\D{0,10}(\d{6})/iu', (string) $text, $m)) {
            return null;
        }

        $verification = PhoneVerification::where('company_id', $company->id)->where('whatsapp_code', $m[1])
            ->pending()->latest('id')->first();

        if ($verification === null) {
            return "Ce code n'est plus valable (il expire au bout de ".self::TTL_MINUTES.' minutes). Recommencez depuis la page du site.';
        }

        if ($verification->phone !== PhoneNumber::normalize($from)) {
            return 'Ce code a été demandé pour le numéro '.PhoneNumber::display($verification->phone)
                .'. Envoyez-le depuis le WhatsApp de ce numéro, ou choisissez « Recevoir un code par SMS » sur la page.';
        }

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
