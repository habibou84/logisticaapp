<?php

namespace App\Services\Platform;

use App\Enums\Role;
use App\Models\PlatformInvoice;
use App\Models\PlatformWavePayment;
use App\Models\User;
use App\Notifications\WavePaymentReceived;
use App\Notifications\WavePaymentToValidate;
use App\Services\Messaging\Gateways\SmsGateway;
use App\Support\Money;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Paiement d'une facture de la plateforme par le lien marchand Wave : l'entreprise annonce le paiement (numéro qui
 * paie), paie dans Wave (lien ou QR code), puis appuie sur « J'ai payé ». Le validateur est prévenu à chaque étape
 * (e-mail d'abord, SMS sinon, cloche de la console) et confirme quand le message de Wave arrive : le paiement est
 * alors enregistré sur la facture.
 */
class WavePayments
{
    public function __construct(private readonly WaveSettings $settings, private readonly Billing $billing, private readonly SmsGateway $sms) {}

    public function start(PlatformInvoice $invoice, User $user, string $payerPhone): PlatformWavePayment
    {
        if (! $this->settings->enabled()) {
            throw ValidationException::withMessages(['payer_phone' => 'Le paiement par Wave n\'est pas encore disponible. Utilisez les instructions de paiement.']);
        }
        if ($invoice->status !== PlatformInvoice::OPEN || $invoice->balance() <= 0) {
            throw ValidationException::withMessages(['payer_phone' => 'Cette facture n\'est plus à payer.']);
        }

        $payment = PlatformWavePayment::create([
            'platform_invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'amount' => $invoice->balance(),
            'payer_phone' => $payerPhone,
            'user_id' => $user->id,
        ]);
        $this->alert($payment, claimed: false);

        return $payment->fresh();
    }

    /**
     * « J'ai payé » : seconde alerte, une seule fois par paiement.
     */
    public function claim(PlatformWavePayment $payment): PlatformWavePayment
    {
        if ($payment->status !== PlatformWavePayment::PENDING || $payment->claimed_at !== null) {
            return $payment;
        }
        $payment->update(['claimed_at' => now()]);
        $this->alert($payment, claimed: true);

        return $payment->fresh();
    }

    /**
     * Décision du validateur : reçu (le paiement est enregistré sur la facture) ou non reçu.
     */
    public function decide(PlatformWavePayment $payment, bool $received, ?User $actor): PlatformWavePayment
    {
        if ($payment->status !== PlatformWavePayment::PENDING) {
            return $payment;
        }

        return DB::transaction(function () use ($payment, $received, $actor) {
            $invoice = PlatformInvoice::query()->lockForUpdate()->findOrFail($payment->platform_invoice_id);
            if ($received && $invoice->status === PlatformInvoice::OPEN && $invoice->balance() > 0) {
                $this->billing->recordPayment($invoice, [
                    'amount' => min($payment->amount, $invoice->balance()),
                    'method' => 'mobile_money',
                    'reference' => 'Wave '.$payment->payer_phone,
                    'paid_on' => now()->toDateString(),
                    'notes' => 'Lien marchand Wave, validé'.($actor ? '' : ' avec le code du validateur'),
                ], $actor);
            }
            $payment->update(['status' => $received ? PlatformWavePayment::RECEIVED : PlatformWavePayment::MISSING, 'decided_by' => $actor?->id, 'decided_at' => now()]);

            return $payment->fresh();
        });
    }

    /**
     * Alerte d'essai depuis la console : e-mail et SMS au validateur, avec ce qui a bloqué.
     *
     * @return array{mail: array{ok: bool, detail: string}, sms: array{ok: bool, detail: string}}
     */
    public function test(): array
    {
        $details = ['test' => true, 'console_url' => self::consoleUrl('/console/paiements')];
        $mail = $this->mail($details);
        $sms = $this->settings->smsReady() ? $this->text($details) : 'Le serveur n\'envoie pas encore de vrais SMS (fournisseur de SMS non configuré).';

        return [
            'mail' => ['ok' => $mail === null, 'detail' => $mail ?? 'E-mail envoyé à '.$this->settings->validatorEmail().'. Regardez aussi dans les spams.'],
            'sms' => ['ok' => $sms === null, 'detail' => $sms ?? 'SMS envoyé au '.$this->settings->validatorPhone().'.'],
        ];
    }

    /**
     * Adresse de la console (admin.jibiat.com), où se trouvent la liste des paiements et la page de validation.
     */
    public static function consoleUrl(string $path = '/'): string
    {
        $base = Tenancy::enabled()
            ? config('platform.scheme').'://'.config('platform.admin_subdomain').'.'.Tenancy::domain()
            : rtrim((string) config('app.url'), '/');

        return $base.$path;
    }

    private function alert(PlatformWavePayment $payment, bool $claimed): void
    {
        $payment->loadMissing(['invoice', 'company']);
        $details = [
            'amount' => Money::format($payment->amount),
            'payer' => $payment->payer_phone,
            'company' => $payment->company?->name ?? '—',
            'number' => $payment->invoice?->number ?? '',
            'claimed' => $claimed,
            'validate_url' => self::consoleUrl(URL::temporarySignedRoute('wave-payments.validate', now()->addDays(7), ['payment' => $payment->id], absolute: false)),
            'console_url' => self::consoleUrl('/console/paiements'),
        ];

        // E-mail d'abord ; SMS sinon (« journal » : le serveur n'envoie pas encore de vrais SMS)
        $channel = $this->mail($details) === null ? 'mail' : ($this->text($details) === null ? ($this->settings->smsReady() ? 'sms' : 'journal') : null);
        $payment->update(['alerts' => [...($payment->alerts ?? []), ['at' => now()->toIso8601String(), 'claimed' => $claimed, 'channel' => $channel]]]);

        // Cloche de la console, quoi qu'il arrive
        try {
            $admins = User::query()->whereNull('company_id')->where('status', 'active')->role(Role::SuperAdmin->value)->get();
            Notification::send($admins, new WavePaymentReceived($payment, $claimed));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return string|null null si l'e-mail est parti, sinon la raison
     */
    private function mail(array $details): ?string
    {
        $email = $this->settings->validatorEmail();
        if ($email === null) {
            return 'Aucune adresse e-mail de validateur.';
        }
        $mailer = $this->settings->mailer();
        if ($mailer === null) {
            return 'Aucun serveur d\'e-mail réglé (Envoi des e-mails, plus bas).';
        }
        try {
            Notification::route('mail', $email)->notifyNow(new WavePaymentToValidate($details, $mailer['mailer'], $mailer['from']));

            return null;
        } catch (Throwable $e) {
            Log::warning('E-mail de paiement Wave non envoyé, passage au SMS', ['invoice' => $details['number'] ?? 'essai', 'error' => $e->getMessage()]);

            return 'Le serveur d\'e-mail a refusé l\'envoi : '.mb_substr($e->getMessage(), 0, 200);
        }
    }

    /**
     * @return string|null null si le SMS est parti, sinon la raison
     */
    private function text(array $details): ?string
    {
        $phone = $this->settings->validatorPhone();
        if ($phone === null) {
            return 'Aucun téléphone de validateur.';
        }
        $text = ($details['test'] ?? false)
            ? 'Jibiat · Essai : les alertes de paiement par SMS fonctionnent.'
            : 'Jibiat · '.($details['claimed'] ? 'Paiement Wave déclaré payé' : 'Paiement Wave à vérifier')
                ." : {$details['amount']} depuis le {$details['payer']}. {$details['company']}, facture {$details['number']}. Confirmer : {$details['validate_url']}";
        try {
            $this->sms->send($phone, $text);

            return null;
        } catch (Throwable $e) {
            Log::warning('SMS de paiement Wave non envoyé', ['invoice' => $details['number'] ?? 'essai', 'error' => $e->getMessage()]);

            return 'Le SMS n\'est pas parti : '.mb_substr($e->getMessage(), 0, 200);
        }
    }
}
