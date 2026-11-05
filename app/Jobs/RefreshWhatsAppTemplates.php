<?php

namespace App\Jobs;

use App\Models\WhatsAppAccount;
use App\Services\Messaging\TemplateAutoSubmit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Lit chez Meta l'état des modèles d'un numéro, puis soumet ceux qui n'ont jamais été soumis.
 */
class RefreshWhatsAppTemplates implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public int $accountId) {}

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    public function handle(TemplateAutoSubmit $templates): void
    {
        $account = WhatsAppAccount::withoutGlobalScopes()->find($this->accountId);

        if ($account) {
            $templates->refresh($account);
        }
    }
}
