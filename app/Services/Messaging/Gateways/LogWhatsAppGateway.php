<?php

namespace App\Services\Messaging\Gateways;

use App\Enums\WhatsAppTemplate;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Aucun envoi réel : le message est écrit dans le journal de l'application.
 */
class LogWhatsAppGateway implements WhatsAppGateway
{
    public function sendTemplate(?WhatsAppAccount $account, string $to, string $template, string $language, array $params): string
    {
        Log::info("[WhatsApp simulé] {$template} → {$to}", ['params' => $params]);

        return 'log-'.Str::uuid();
    }

    public function sendReply(?WhatsAppAccount $account, string $to, string $text, array $buttons = []): string
    {
        Log::info("[WhatsApp simulé] réponse → {$to}", ['text' => $text, 'buttons' => $buttons]);

        return 'log-'.Str::uuid();
    }

    public function templates(?WhatsAppAccount $account): array
    {
        return array_map(fn (WhatsAppTemplate $t) => [
            'name' => $t->value,
            'language' => WhatsAppTemplate::LANGUAGE,
            'status' => 'approved',
            'category' => 'utility',
            'rejected_reason' => null,
        ], WhatsAppTemplate::cases());
    }
}
