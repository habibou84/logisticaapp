<?php

namespace App\Services\Messaging\Gateways;

use App\Models\WhatsAppAccount;
use App\Services\Messaging\MessagingException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * API Cloud WhatsApp de Meta (graph.facebook.com).
 */
class MetaCloudGateway implements WhatsAppGateway
{
    // Codes d'erreur Meta pour lesquels une nouvelle tentative a un sens
    private const RETRYABLE_CODES = [1, 2, 4, 17, 341, 80007, 130429, 131000, 131016, 131048, 131056, 133004];

    public function sendTemplate(?WhatsAppAccount $account, string $to, string $template, string $language, array $params): string
    {
        $account = $this->ensureConfigured($account);

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => ltrim($to, '+'),
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $language],
            ],
        ];

        if ($params !== []) {
            $payload['template']['components'] = [[
                'type' => 'body',
                'parameters' => array_map(fn (string $p) => ['type' => 'text', 'text' => $p], $params),
            ]];
        }

        $response = $this->call(fn () => $this->client($account)->post("{$account->phone_number_id}/messages", $payload));

        return (string) $response->json('messages.0.id');
    }

    public function sendReply(?WhatsAppAccount $account, string $to, string $text, array $buttons = []): string
    {
        $account = $this->ensureConfigured($account);

        $payload = ['messaging_product' => 'whatsapp', 'to' => ltrim($to, '+')];

        if ($buttons === []) {
            $payload += ['type' => 'text', 'text' => ['body' => mb_substr($text, 0, 4096), 'preview_url' => true]];
        } else {
            // Boutons de réponse rapide : 3 au plus, libellés de 20 caractères au plus
            $payload += ['type' => 'interactive', 'interactive' => [
                'type' => 'button',
                'body' => ['text' => mb_substr($text, 0, 1024)],
                'action' => ['buttons' => array_map(
                    fn ($id, $title) => ['type' => 'reply', 'reply' => ['id' => (string) $id, 'title' => mb_substr($title, 0, 20)]],
                    array_keys(array_slice($buttons, 0, 3, true)),
                    array_slice($buttons, 0, 3, true),
                )],
            ]];
        }

        $response = $this->call(fn () => $this->client($account)->post("{$account->phone_number_id}/messages", $payload));

        return (string) $response->json('messages.0.id');
    }

    public function templates(?WhatsAppAccount $account): array
    {
        $account = $this->ensureConfigured($account);

        if (blank($account->waba_id)) {
            throw new MessagingException('Identifiant du compte WhatsApp Business (WABA) manquant.');
        }

        $response = $this->call(fn () => $this->client($account)->get("{$account->waba_id}/message_templates", [
            'fields' => 'name,language,status,category,rejected_reason',
            'limit' => 200,
        ]));

        return array_map(fn (array $t) => [
            'name' => $t['name'],
            'language' => $t['language'],
            'status' => strtolower($t['status'] ?? 'pending'),
            'category' => isset($t['category']) ? strtolower($t['category']) : null,
            'rejected_reason' => ($t['rejected_reason'] ?? 'NONE') === 'NONE' ? null : $t['rejected_reason'],
        ], $response->json('data', []));
    }

    private function ensureConfigured(?WhatsAppAccount $account): WhatsAppAccount
    {
        if (! $account?->isConfigured()) {
            throw new MessagingException('Numéro WhatsApp non configuré (Paramètres > WhatsApp).');
        }

        return $account;
    }

    private function client(WhatsAppAccount $account): PendingRequest
    {
        $base = rtrim(config('messaging.whatsapp.graph_url'), '/').'/'.config('messaging.whatsapp.graph_version');

        return Http::baseUrl($base)->withToken($account->apiToken())->acceptJson()->timeout(15);
    }

    /**
     * @param  callable(): Response  $request
     */
    private function call(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new MessagingException('Meta injoignable : '.$e->getMessage(), retryable: true);
        }

        if ($response->successful()) {
            return $response;
        }

        $code = $response->json('error.code');
        $message = $response->json('error.error_data.details') ?? $response->json('error.message') ?? 'Erreur HTTP '.$response->status();

        throw new MessagingException(
            "Meta ({$code}) : {$message}",
            retryable: $response->serverError() || $response->status() === 429 || in_array($code, self::RETRYABLE_CODES, true),
            providerCode: $code !== null ? (string) $code : null,
        );
    }
}
