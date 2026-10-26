<?php

namespace App\Console\Commands;

use App\Exceptions\BusinessRuleException;
use App\Models\ClientCreditNote;
use App\Models\ClientInvoice;
use App\Services\Finance\Fne;
use Illuminate\Console\Command;

/**
 * Facture normalisée (FNE) : relance des factures et avoirs restés « à certifier » (FNE injoignable). Les pièces
 * refusées par la FNE attendent qu'on corrige leurs données.
 */
class RetryFneCertification extends Command
{
    protected $signature = 'fne:retry';

    protected $description = 'Certifie auprès de la FNE les factures et avoirs restés à certifier';

    public function handle(Fne $fne): int
    {
        $done = 0;
        // Une heure au plus entre deux essais après une douzaine d'échecs
        $due = fn ($q) => $q->where('fne_status', 'pending')->where(fn ($q) => $q->where('fne_attempts', '<', 12)->orWhere('updated_at', '<', now()->subHour()));

        ClientInvoice::withoutGlobalScopes()->with('company')->tap($due)->limit(50)->get()
            ->each(function (ClientInvoice $invoice) use ($fne, &$done) {
                if (! $fne->enabled($invoice->company)) {
                    return;
                }
                try {
                    $done += (int) ($fne->certify($invoice)->fne_status === 'certified');
                } catch (BusinessRuleException) {
                    // Refusée : l'erreur est sur la facture
                }
            });

        ClientCreditNote::withoutGlobalScopes()->with(['lines', 'invoice' => fn ($q) => $q->withoutGlobalScopes()->with('company')])->tap($due)->limit(50)->get()
            ->each(function (ClientCreditNote $note) use ($fne, &$done) {
                if ($note->invoice?->fne_status !== 'certified' || ! $fne->enabled($note->invoice->company)) {
                    return;
                }
                try {
                    $done += (int) ($fne->refund($note)->fne_status === 'certified');
                } catch (BusinessRuleException) {
                }
            });

        $this->info("{$done} pièce(s) certifiée(s).");

        return self::SUCCESS;
    }
}
