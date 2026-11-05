<?php

namespace App\Console\Commands;

use App\Models\WhatsAppAccount;
use App\Services\Messaging\TemplateAutoSubmit;
use Illuminate\Console\Command;

class SubmitWhatsAppTemplates extends Command
{
    protected $signature = 'whatsapp:templates';

    protected $description = 'Relit chez Meta l\'état des modèles WhatsApp des entreprises et soumet ceux qui ne l\'ont jamais été';

    public function handle(TemplateAutoSubmit $templates): int
    {
        $submitted = 0;
        WhatsAppAccount::withoutGlobalScopes()->where('owner_type', 'company')->whereNotNull('waba_id')
            ->each(function (WhatsAppAccount $account) use ($templates, &$submitted) {
                $submitted += $templates->refresh($account);
            });

        $this->info($submitted.' modèle(s) soumis à Meta.');

        return self::SUCCESS;
    }
}
