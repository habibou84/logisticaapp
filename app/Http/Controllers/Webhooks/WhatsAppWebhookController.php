<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\MessageStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundWhatsApp;
use App\Models\InboundMessage;
use App\Models\OutboundMessage;
use App\Models\WhatsAppAccount;
use App\Services\Messaging\Messenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook de l'application Meta : accusés de remise des messages envoyés et
 * messages reçus des marchands (création de course par WhatsApp).
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(private readonly Messenger $messenger) {}

    /**
     * Vérification de l'abonnement par Meta (hub.challenge).
     */
    public function verify(Request $request): Response
    {
        $token = config('messaging.whatsapp.verify_token');

        abort_unless(
            filled($token) && $request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token')),
            403,
        );

        return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
    }

    public function handle(Request $request): JsonResponse
    {
        abort_unless($this->hasValidSignature($request), 403, 'Signature invalide.');

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    $this->applyStatus($status);
                }

                $account = WhatsAppAccount::withoutGlobalScopes()
                    ->where('phone_number_id', $change['value']['metadata']['phone_number_id'] ?? '')
                    ->first();

                foreach ($change['value']['messages'] ?? [] as $message) {
                    $this->receive($account, $message);
                }
            }
        }

        return response()->json(['received' => true]);
    }

    /**
     * Message d'un expéditeur : confié à la conversation (création de course…).
     *
     * @param  array<string, mixed>  $message
     */
    private function receive(?WhatsAppAccount $account, array $message): void
    {
        if ($account === null || ! isset($message['id'], $message['from'])) {
            return;
        }

        // Meta peut renvoyer le même webhook : un message n'est traité qu'une fois
        if (InboundMessage::withoutGlobalScopes()->where('provider_message_id', $message['id'])->exists()) {
            return;
        }

        $type = $message['type'] ?? 'other';
        [$kind, $text, $buttonId] = match ($type) {
            'text' => ['text', $message['text']['body'] ?? '', null],
            'interactive' => ['button',
                $message['interactive']['button_reply']['title'] ?? $message['interactive']['list_reply']['title'] ?? null,
                $message['interactive']['button_reply']['id'] ?? $message['interactive']['list_reply']['id'] ?? null],
            'button' => ['text', $message['button']['text'] ?? '', null],
            default => [$type, null, null],
        };

        ProcessInboundWhatsApp::dispatch($account->company_id, '+'.ltrim($message['from'], '+'), $kind, $text, $buttonId, [
            'whatsapp_account_id' => $account->id,
            'provider_message_id' => $message['id'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function applyStatus(array $status): void
    {
        $newStatus = MessageStatus::tryFrom($status['status'] ?? '');
        if (! isset($status['id']) || $newStatus === null || $newStatus === MessageStatus::Queued) {
            return;
        }

        $message = OutboundMessage::withoutGlobalScopes()->where('provider_message_id', $status['id'])->first();
        if ($message === null) {
            return;
        }

        $error = null;
        if ($newStatus === MessageStatus::Failed) {
            $first = $status['errors'][0] ?? [];
            $error = trim(sprintf('Meta (%s) : %s', $first['code'] ?? '?', $first['error_data']['details'] ?? $first['message'] ?? $first['title'] ?? 'échec de remise'));
        }

        if ($message->advanceTo($newStatus, $error) && $newStatus === MessageStatus::Failed) {
            $this->messenger->fallbackToSms($message);
        }
    }

    /**
     * Signature HMAC-SHA256 du corps brut avec le secret de l'application Meta.
     * Sans secret configuré, les appels ne sont acceptés qu'en développement (pas en préproduction).
     */
    private function hasValidSignature(Request $request): bool
    {
        $secret = config('messaging.whatsapp.app_secret');

        if (blank($secret)) {
            return app()->environment('local', 'testing');
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, (string) $request->header('X-Hub-Signature-256'));
    }
}
