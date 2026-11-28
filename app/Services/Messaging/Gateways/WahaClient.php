<?php

namespace App\Services\Messaging\Gateways;

use App\Services\Messaging\MessagingException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * WAHA (conteneur « whatsapp-qr ») : session WhatsApp Web reliée par QR code.
 * Une seule session (« default ») : celle du WhatsApp des alertes de l'entreprise.
 */
class WahaClient
{
    public static function available(): bool
    {
        return filled(config('messaging.qr.api_key')) && filled(config('messaging.qr.url'));
    }

    /**
     * État de la session : status (STOPPED, STARTING, SCAN_QR_CODE, WORKING, FAILED…), numéro et nom une fois reliée.
     * Session inconnue du service : STOPPED.
     *
     * @return array{status: string, phone: ?string, name: ?string}
     */
    public function info(): array
    {
        $response = $this->call(fn () => $this->client()->get('api/sessions/'.$this->session()), allowMissing: true);
        if ($response->status() === 404) {
            return ['status' => 'STOPPED', 'phone' => null, 'name' => null];
        }

        $id = (string) $response->json('me.id');

        return [
            'status' => (string) ($response->json('status') ?: 'STOPPED'),
            'phone' => $id !== '' ? '+'.preg_replace('/\D/', '', strtok($id, '@:')) : null,
            'name' => $response->json('me.pushName') ?: null,
        ];
    }

    /**
     * Démarre la session (la crée la première fois) : elle affiche ensuite un QR code à scanner.
     */
    public function start(): void
    {
        $session = $this->session();
        $exists = $this->call(fn () => $this->client()->get("api/sessions/{$session}"), allowMissing: true)->status() !== 404;

        $this->call(fn () => $exists
            ? $this->client()->post("api/sessions/{$session}/start")
            : $this->client()->post('api/sessions', ['name' => $session, 'start' => true]));
    }

    /**
     * QR code à scanner, en image PNG encodée en base64 (null tant qu'il n'est pas prêt).
     */
    public function qr(): ?string
    {
        $response = $this->call(fn () => $this->client()->accept('application/json')->get("api/{$this->session()}/auth/qr"), allowMissing: true);

        return $response->successful() ? ($response->json('data') ?: null) : null;
    }

    /**
     * Délie le numéro (le téléphone n'affiche plus l'appareil) et arrête la session.
     */
    public function logout(): void
    {
        $this->call(fn () => $this->client()->delete('api/sessions/'.$this->session()), allowMissing: true);
    }

    /**
     * Message texte vers un numéro E.164 ; renvoie l'identifiant du message.
     *
     * @throws MessagingException
     */
    public function sendText(string $to, string $text): string
    {
        $response = $this->call(fn () => $this->client()->post('api/sendText', [
            'session' => $this->session(),
            'chatId' => preg_replace('/\D/', '', $to).'@c.us',
            'text' => mb_substr($text, 0, 4096),
            'linkPreview' => false,
        ]));

        $id = $response->json('id');

        return 'qr-'.(is_array($id) ? ($id['_serialized'] ?? $id['id'] ?? '') : (string) $id);
    }

    private function session(): string
    {
        return (string) config('messaging.qr.session', 'default');
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('messaging.qr.url'), '/'))
            ->withHeaders(['X-Api-Key' => (string) config('messaging.qr.api_key')])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20);
    }

    /**
     * @param  Closure(): Response  $request
     *
     * @throws MessagingException
     */
    private function call(Closure $request, bool $allowMissing = false): Response
    {
        if (! self::available()) {
            throw new MessagingException('WhatsApp des alertes non installé sur le serveur (WHATSAPP_QR_API_KEY).');
        }

        try {
            $response = $request();
        } catch (ConnectionException) {
            throw new MessagingException('Service WhatsApp des alertes injoignable.', retryable: true);
        }

        if ($response->successful() || ($allowMissing && $response->status() === 404)) {
            return $response;
        }

        $detail = $response->json('message') ?? $response->json('error') ?? $response->body();
        $detail = is_array($detail) ? implode(' ', array_map('strval', $detail)) : (string) $detail;
        $notWorking = $response->status() === 422 || str_contains($detail, 'WORKING') || str_contains($detail, 'not found');

        throw new MessagingException(
            $notWorking ? 'WhatsApp des alertes déconnecté : scannez à nouveau le QR code.' : 'WhatsApp des alertes : '.mb_substr(trim($detail), 0, 200),
            retryable: $response->serverError() && ! $notWorking,
            providerCode: (string) $response->status(),
        );
    }
}
