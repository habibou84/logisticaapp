<?php

namespace App\Services\Messaging\Gateways;

use App\Enums\WhatsAppTemplate;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Aucun envoi réel : le message est écrit dans le journal de l'application. En production, les codes (4 chiffres ou
 * plus : vérification, livraison) y sont masqués.
 */
class LogWhatsAppGateway implements WhatsAppGateway
{
    /**
     * @template T of string|array
     *
     * @param  T  $value
     * @return T
     */
    public static function mask(string|array $value): string|array
    {
        if (! app()->isProduction()) {
            return $value;
        }
        if (is_array($value)) {
            return array_map(fn ($v) => is_string($v) || is_array($v) ? self::mask($v) : $v, $value);
        }

        return (string) preg_replace('/(?<![\d+])\d{4,8}(?!\d)/', '••••', $value);
    }

    public function sendTemplate(?WhatsAppAccount $account, string $to, string $template, string $language, array $params): string
    {
        Log::info("[WhatsApp simulé] {$template} → {$to}", ['params' => self::mask($params)]);

        return 'log-'.Str::uuid();
    }

    public function sendReply(?WhatsAppAccount $account, string $to, string $text, array $buttons = []): string
    {
        Log::info("[WhatsApp simulé] réponse → {$to}", ['text' => self::mask($text), 'buttons' => $buttons]);

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
