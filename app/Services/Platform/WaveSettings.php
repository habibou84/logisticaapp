<?php

namespace App\Services\Platform;

use App\Models\PlatformSetting;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Réglages du paiement des factures par le lien marchand Wave, choisis dans la console : lien et QR code, validateur
 * (e-mail, téléphone, code haché) et serveur d'e-mail des alertes (Gmail…, mot de passe chiffré).
 */
class WaveSettings
{
    public function enabled(): bool
    {
        return $this->link() !== null || $this->qrUrl() !== null;
    }

    public function link(): ?string
    {
        return $this->value('wave.link');
    }

    public function qrUrl(): ?string
    {
        $path = $this->value('wave.qr');

        return $path ? $this->disk()->url($path) : null;
    }

    public function validatorEmail(): ?string
    {
        return $this->value('wave.validator_email');
    }

    public function validatorPhone(): ?string
    {
        return $this->value('wave.validator_phone');
    }

    public function checkPin(string $code): bool
    {
        $hash = $this->value('wave.validator_pin');

        return $hash !== null && Hash::check($code, $hash);
    }

    /**
     * Lien de paiement avec le montant : {montant} remplacé, sinon ajouté en ?amount=.
     */
    public function linkFor(int $amount): ?string
    {
        $link = $this->link();

        return match (true) {
            $link === null => null,
            str_contains($link, '{montant}') => str_replace('{montant}', (string) $amount, $link),
            default => $link.(str_contains($link, '?') ? '&' : '?').'amount='.$amount,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(array $data): void
    {
        foreach (['link', 'validator_email', 'validator_phone'] as $key) {
            if (array_key_exists($key, $data)) {
                PlatformSetting::put("wave.{$key}", filled($data[$key]) ? (string) $data[$key] : null);
            }
        }
        if (filled($data['validator_pin'] ?? null)) {
            PlatformSetting::put('wave.validator_pin', Hash::make((string) $data['validator_pin']));
        }
    }

    public function storeQr(UploadedFile $file): void
    {
        $this->removeQr();
        PlatformSetting::put('wave.qr', $file->store('platform', ['disk' => 'public', 'visibility' => 'public']));
    }

    public function removeQr(): void
    {
        if (($path = $this->value('wave.qr')) !== null) {
            $this->disk()->delete($path);
            PlatformSetting::put('wave.qr', null);
        }
    }

    /**
     * Un serveur d'e-mail est réglé : celui de la console, sinon celui du serveur (le pilote « log » n'envoie rien).
     */
    public function mailReady(): bool
    {
        return $this->smtp() !== null || config('mail.default') !== 'log';
    }

    /**
     * Les SMS partent vraiment (le pilote « log » les écrit seulement dans le journal du serveur).
     */
    public function smsReady(): bool
    {
        return ! in_array(config('messaging.sms.driver'), ['log', 'none', null], true);
    }

    /**
     * Messagerie des alertes : le serveur SMTP de la console (Gmail…), sinon celle du serveur.
     *
     * @return array{mailer: string, from: ?string}|null
     */
    public function mailer(): ?array
    {
        if (($smtp = $this->smtp()) !== null) {
            config(['mail.mailers.console_smtp' => [
                'transport' => 'smtp',
                'scheme' => (int) $smtp['port'] === 465 ? 'smtps' : 'smtp',
                'host' => $smtp['host'],
                'port' => (int) $smtp['port'],
                'username' => $smtp['username'],
                'password' => $smtp['password'],
                'timeout' => 15,
            ]]);
            Mail::purge('console_smtp');

            return ['mailer' => 'console_smtp', 'from' => $smtp['from_address'] ?: $smtp['username']];
        }

        return config('mail.default') !== 'log' ? ['mailer' => (string) config('mail.default'), 'from' => null] : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateMail(array $data): void
    {
        if (blank($data['host'] ?? null)) {
            PlatformSetting::query()->where('key', 'like', 'mail.%')->delete();

            return;
        }
        foreach (['host', 'port', 'username', 'from_address'] as $key) {
            if (array_key_exists($key, $data)) {
                PlatformSetting::put("mail.{$key}", filled($data[$key]) ? (string) $data[$key] : null);
            }
        }
        if (filled($data['password'] ?? null)) {
            PlatformSetting::put('mail.password', Crypt::encryptString((string) $data['password']));
        }
    }

    /**
     * Réglages pour la console (code et mot de passe jamais renvoyés).
     *
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'link' => $this->link(),
            'qr_url' => $this->qrUrl(),
            'validator_email' => $this->validatorEmail(),
            'validator_phone' => $this->validatorPhone(),
            'pin_set' => $this->value('wave.validator_pin') !== null,
            'enabled' => $this->enabled(),
            'mail' => [
                'source' => $this->value('mail.host') !== null ? 'console' : (config('mail.default') !== 'log' ? 'server' : null),
                'host' => $this->value('mail.host'),
                'port' => $this->value('mail.port'),
                'username' => $this->value('mail.username'),
                'from_address' => $this->value('mail.from_address'),
                'password_set' => $this->value('mail.password') !== null,
            ],
            'sms_ready' => $this->smsReady(),
        ];
    }

    /**
     * @return array{host: string, port: string, username: ?string, password: ?string, from_address: ?string}|null
     */
    private function smtp(): ?array
    {
        $host = $this->value('mail.host');
        if ($host === null) {
            return null;
        }
        $password = $this->value('mail.password');

        return [
            'host' => $host,
            'port' => $this->value('mail.port') ?? '587',
            'username' => $this->value('mail.username'),
            'password' => $password !== null ? Crypt::decryptString($password) : null,
            'from_address' => $this->value('mail.from_address'),
        ];
    }

    private function value(string $key): ?string
    {
        $value = PlatformSetting::get($key);

        return filled($value) ? $value : null;
    }

    private function disk(): Filesystem
    {
        return Storage::disk('public');
    }
}
