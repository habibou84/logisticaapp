<?php

namespace App\Services\Finance;

use App\Enums\ClientInvoiceStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\ClientCreditNote;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Avoirs sur les factures émises des clients : total (tout ce qui reste), ou partiel (des lignes de la facture,
 * en tout ou en partie, et un montant libre). Même TVA que la facture ; numéro AV-AAAAMM-0001. L'avoir réduit
 * le reste à payer ; sur une facture déjà payée, la différence est à rembourser au client.
 */
class ClientCreditNotes
{
    public function __construct(private readonly ClientInvoicing $invoicing) {}

    /**
     * @param  list<array{line_id: int, quantity: int}>  $lines  lignes de la facture reprises
     * @param  array{label: string, amount: int}|null  $extra  montant libre (même base que la facture : HT ou TTC)
     */
    public function create(ClientInvoice $invoice, User $actor, string $reason, bool $full, array $lines = [], ?array $extra = null): ClientCreditNote
    {
        if (! in_array($invoice->status, [ClientInvoiceStatus::Issued, ClientInvoiceStatus::Paid], true)) {
            throw new BusinessRuleException('Un avoir se fait sur une facture émise.', 'status');
        }
        $remaining = $invoice->total - $invoice->credited_amount;
        if ($remaining <= 0) {
            throw new BusinessRuleException('Cette facture est déjà entièrement couverte par des avoirs.', 'status');
        }
        // Facture normalisée (FNE) : l'avoir suit la facture certifiée, article par article
        if (in_array($invoice->fne_status, ['pending', 'failed'], true)) {
            throw new BusinessRuleException('La facture n\'est pas encore certifiée par la FNE : certifiez-la avant de faire un avoir.', 'fne');
        }
        $fne = $invoice->fne_status === 'certified';
        if ($fne && $extra !== null && (int) ($extra['amount'] ?? 0) > 0) {
            throw new BusinessRuleException('Facture normalisée (FNE) : l\'avoir reprend des lignes de la facture, pas un montant libre.', 'extra');
        }

        return DB::transaction(function () use ($invoice, $actor, $reason, $full, $lines, $extra, $remaining, $fne) {
            $invoice = ClientInvoice::withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
            [$rows, $amounts] = $full ? $this->fullRows($invoice, $remaining) : $this->partialRows($invoice, $lines, $extra);
            if ($amounts['total'] <= 0) {
                throw new BusinessRuleException('Choisissez au moins une ligne ou un montant.', 'lines');
            }
            if ($amounts['total'] > $remaining) {
                throw new BusinessRuleException('L\'avoir dépasse ce qui reste sur la facture ('.Money::format($remaining).').', 'lines');
            }

            // Ce qui avait déjà été payé au-delà du nouveau reste dû : à rembourser
            $refund = min($amounts['total'], max(0, $invoice->amount_paid - ($remaining - $amounts['total'])));
            $note = ClientCreditNote::create([
                'company_id' => $invoice->company_id,
                'merchant_id' => $invoice->merchant_id,
                'client_invoice_id' => $invoice->id,
                'activity' => $invoice->activity,
                'number' => $this->nextNumber($invoice),
                'issued_at' => $this->today($invoice)->toDateString(),
                'reason' => $reason,
                'vat_rate' => $invoice->vat_rate,
                ...$amounts,
                'refund_due' => $refund,
                'created_by' => $actor->id,
            ]);
            foreach ($rows as $row) {
                $note->lines()->create($row);
            }

            $invoice->update(['credited_amount' => $invoice->credited_amount + $amounts['total']]);
            $this->invoicing->settle($invoice);
            // Certifié avec la facture ; refusé par la FNE : rien n'est fait ; FNE injoignable : relancé plus tard
            if ($fne) {
                app(Fne::class)->refund($note->load('lines'));
            }

            return $note->load('lines');
        });
    }

    /**
     * Avoir total : chaque ligne pour ce qui n'a pas encore été repris, montants égaux au reste de la facture.
     *
     * @return array{0: list<array<string, mixed>>, 1: array{total_ht: int, vat_amount: int, total: int}}
     */
    private function fullRows(ClientInvoice $invoice, int $remaining): array
    {
        $credited = $this->creditedQuantities($invoice);
        $rows = [];
        foreach ($invoice->lines()->get() as $line) {
            $quantity = $line->quantity - ($credited[$line->id] ?? 0);
            if ($line->amount !== 0 && $quantity > 0) {
                $rows[] = ['client_invoice_line_id' => $line->id, 'label' => $line->label, 'quantity' => $quantity, 'unit_price' => $line->unit_price, 'amount' => $quantity * $line->unit_price];
            }
        }
        // Montant libre déjà repris (ou lignes à zéro) : le reste de la facture, en une ligne
        $freeCredited = DB::table('client_credit_note_lines')->whereNull('client_invoice_line_id')
            ->whereIn('client_credit_note_id', $invoice->creditNotes()->select('id'))->exists();
        if ($rows === [] || $freeCredited) {
            $rows = [['client_invoice_line_id' => null, 'label' => 'Reste de la facture '.$invoice->number, 'quantity' => 1, 'unit_price' => 0, 'amount' => 0]];
        }
        $ht = max(0, $invoice->total_ht - (int) $invoice->creditNotes()->sum('total_ht'));
        $vat = $remaining - $ht;
        if (count($rows) === 1 && $rows[0]['client_invoice_line_id'] === null) {
            $base = $invoice->vat_basis === 'ttc' ? $remaining : $ht;
            $rows[0] = [...$rows[0], 'unit_price' => $base, 'amount' => $base];
        }

        return [$rows, ['total_ht' => $ht, 'vat_amount' => max(0, $vat), 'total' => $remaining]];
    }

    /**
     * Avoir partiel : quantités de lignes (jamais plus que facturé moins déjà repris) et montant libre.
     *
     * @param  list<array{line_id: int, quantity: int}>  $lines
     * @param  array{label: string, amount: int}|null  $extra
     * @return array{0: list<array<string, mixed>>, 1: array{total_ht: int, vat_amount: int, total: int}}
     */
    private function partialRows(ClientInvoice $invoice, array $lines, ?array $extra): array
    {
        $invoiceLines = $invoice->lines()->get()->keyBy('id');
        $credited = $this->creditedQuantities($invoice);
        $rows = [];
        foreach ($lines as $wanted) {
            $quantity = (int) $wanted['quantity'];
            if ($quantity <= 0) {
                continue;
            }
            /** @var ClientInvoiceLine|null $line */
            $line = $invoiceLines->get((int) $wanted['line_id']);
            if ($line === null || $line->amount <= 0) {
                throw new BusinessRuleException('Cette ligne ne peut pas être reprise sur un avoir.', 'lines');
            }
            $left = $line->quantity - ($credited[$line->id] ?? 0);
            if ($quantity > $left) {
                throw new BusinessRuleException("« {$line->label} » : au plus {$left} à reprendre.", 'lines');
            }
            $rows[] = ['client_invoice_line_id' => $line->id, 'label' => $line->label, 'quantity' => $quantity, 'unit_price' => $line->unit_price, 'amount' => $quantity * $line->unit_price];
        }
        if ($extra !== null && (int) $extra['amount'] > 0) {
            $rows[] = ['client_invoice_line_id' => null, 'label' => mb_substr($extra['label'], 0, 255), 'quantity' => 1, 'unit_price' => (int) $extra['amount'], 'amount' => (int) $extra['amount']];
        }

        return [$rows, Vat::split((int) array_sum(array_column($rows, 'amount')), (float) $invoice->vat_rate, $invoice->vat_basis ?? 'ht')];
    }

    /** @return array<int, int> quantités déjà reprises par ligne de la facture */
    public function creditedQuantities(ClientInvoice $invoice): array
    {
        return DB::table('client_credit_note_lines')
            ->join('client_credit_notes', 'client_credit_notes.id', '=', 'client_credit_note_lines.client_credit_note_id')
            ->where('client_credit_notes.client_invoice_id', $invoice->id)
            ->whereNotNull('client_invoice_line_id')
            ->groupBy('client_invoice_line_id')
            ->selectRaw('client_invoice_line_id, SUM(quantity) as qty')
            ->pluck('qty', 'client_invoice_line_id')->map(fn ($q) => (int) $q)->all();
    }

    /** AV-AAAAMM-0001 : une série par entreprise, selon le mois de l'avoir */
    private function nextNumber(ClientInvoice $invoice): string
    {
        $prefix = config('platform.client_invoices.credit_note_prefix', 'AV').'-'.$this->today($invoice)->format('Ym').'-';
        $last = ClientCreditNote::withoutGlobalScopes()->where('company_id', $invoice->company_id)->where('number', 'like', $prefix.'%')
            ->lockForUpdate()->pluck('number')->map(fn ($n) => (int) substr($n, strlen($prefix)))->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    private function today(ClientInvoice $invoice): CarbonImmutable
    {
        return CarbonImmutable::now($invoice->company->timezone ?: config('app.timezone'));
    }
}
