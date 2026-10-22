<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Notifications\WhatsAppAccessExpiring;
use App\Services\Messaging\WhatsAppConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class CheckWhatsAppTokens extends Command
{
    protected $signature = 'whatsapp:tokens';

    protected $description = 'Accès WhatsApp limités dans le temps : relais par la plateforme, alerte 7 jours avant l\'échéance, déconnexion à l\'expiration';

    public function handle(WhatsAppConnection $connection): int
    {
        $adopted = $warned = $expired = 0;

        WhatsAppAccount::withoutGlobalScopes()
            ->where('platform_token', false)
            ->whereNotNull('token_expires_at')
            ->where('status', 'connected')
            ->each(function (WhatsAppAccount $account) use ($connection, &$adopted, &$warned, &$expired) {
                // Utilisateur système de la plateforme configuré depuis la connexion : il prend le relais
                if ($connection->adoptWithPlatform($account)) {
                    $adopted++;

                    return;
                }

                if ($account->token_expires_at->isPast()) {
                    $account->forceFill([
                        'status' => 'error',
                        'last_error' => 'Accès Meta expiré : cliquez sur « Connecter WhatsApp » pour le renouveler.',
                    ])->save();
                    $this->notifyAdmins($account);
                    $expired++;
                } elseif ($account->token_expires_at->lte(now()->addDays(7)) && $account->token_warned_at === null) {
                    $account->forceFill(['token_warned_at' => now()])->save();
                    $this->notifyAdmins($account);
                    $warned++;
                }
            });

        $this->info("{$adopted} relais par la plateforme, {$warned} alerte(s), {$expired} accès expiré(s).");

        return self::SUCCESS;
    }

    private function notifyAdmins(WhatsAppAccount $account): void
    {
        $admins = User::query()->where('company_id', $account->company_id)->whereNull('merchant_id')
            ->where('status', 'active')->role(Role::Admin->value)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new WhatsAppAccessExpiring($account));
        }
    }
}
