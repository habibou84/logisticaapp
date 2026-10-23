<?php

namespace App\Services\Finance;

use App\Enums\Activity;
use App\Enums\ClientInvoiceStatus;
use App\Enums\InvoicePaymentMethod;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\BillingPlan;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ClientSupplement;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Factures du mois des clients sous formule, payées à part : une par client, mois et activité (distribution,
 * MAD), chaque activité avec sa série de numéros.
 *
 * Calculée en brouillon (chaque 1er du mois pour le mois écoulé, ou à la demande), la facture reprend :
 * le forfait de chaque formule en cours dans le mois, les plis et colis (ou courses) du forfait et ceux
 * au-delà, le poids au-delà de 5 kg, puis les envois facturés à l'unité (chèques, villes de l'intérieur,
 * distribution à l'unité, hors des communes du forfait). Un brouillon se recalcule (les lignes ajoutées à la
 * main sont gardées), puis s'émet : il reçoit son numéro et ne change plus. Courses comptées : celles créées
 * dans le mois, hors courses annulées ou refusées.
 */
class ClientInvoicing
{
    /**
     * Brouillons du mois pour tous les clients concernés (sans toucher aux factures déjà émises).
     *
     * @param  list<string>|null  $activities  activités à facturer (périmètre de l'utilisateur), null : toutes
     * @return int nombre de factures calculées
     */
    public function generate(Company $company, CarbonImmutable $month, ?array $activities = null): int
    {
        [$from, $to] = $this->bounds($company, $month);

        // Couples client et activité : une formule dans le mois, ou des courses facturées au mois
        $pairs = BillingPlan::forCompany($company->id)->overlapping($from->toDateString(), $to->toDateString())
            ->get(['merchant_id', 'activity'])->map(fn ($p) => $p->merchant_id.'|'.$p->activity->value)
            ->merge($this->orders($company->id, null, null, $from, $to)->get(['merchant_id', 'activity'])->map(fn ($o) => $o->merchant_id.'|'.$o->activity->value))
            ->merge($this->supplements($company->id, null, null, $from, $to)->get(['merchant_id', 'activity'])->map(fn ($s) => $s->merchant_id.'|'.$s->activity->value))
            ->unique()->sort()
            ->filter(fn ($p) => $activities === null || in_array(explode('|', $p)[1], $activities, true))->values();
        $merchants = Merchant::withoutGlobalScopes()->with('company')->where('company_id', $company->id)->whereIn('id', $pairs->map(fn ($p) => (int) explode('|', $p)[0]))->get()->keyBy('id');

        $count = 0;
        foreach ($pairs as $pair) {
            [$merchantId, $activity] = explode('|', $pair);
            if (isset($merchants[(int) $merchantId]) && $this->compute($merchants[(int) $merchantId], Activity::from($activity), $month) !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Calcule (ou recalcule) le brouillon du mois d'un client pour une activité. Null : rien à facturer, ou
     * facture déjà émise.
     */
    public function compute(Merchant $merchant, Activity $activity, CarbonImmutable $month): ?ClientInvoice
    {
        $company = $merchant->company;
        [$from, $to] = $this->bounds($company, $month);
        $period = $from->startOfMonth()->toDateString();

        $invoice = ClientInvoice::forCompany($company->id)->withoutGlobalScopes()->where('company_id', $company->id)
            ->where('merchant_id', $merchant->id)->where('activity', $activity->value)->whereDate('period', $period)->first();
        if ($invoice !== null && $invoice->status !== ClientInvoiceStatus::Draft) {
            return null;
        }

        $lines = $this->lines($merchant, $activity, $from, $to);
        if ($lines === [] && ($invoice === null || ! $invoice->lines()->where('manual', true)->exists())) {
            $invoice?->delete();

            return null;
        }

        return DB::transaction(function () use ($company, $merchant, $activity, $period, $invoice, $lines) {
            $invoice ??= ClientInvoice::create([
                'company_id' => $company->id,
                'merchant_id' => $merchant->id,
                'activity' => $activity,
                'period' => $period,
                'status' => ClientInvoiceStatus::Draft,
            ]);

            $invoice->lines()->where('manual', false)->delete();
            foreach ($lines as $i => $line) {
                $invoice->lines()->create([...$line, 'position' => $i]);
            }

            return $this->refreshTotal($invoice);
        });
    }

    /**
     * Lignes calculées du mois.
     *
     * @return list<array{kind: string, label: string, quantity: int, unit_price: int, amount: int}>
     */
    public function lines(Merchant $merchant, Activity $activity, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $orders = $this->orders($merchant->company_id, $merchant->id, $activity, $from, $to)->get();
        $plans = BillingPlan::forCompany($merchant->company_id)->where('merchant_id', $merchant->id)->where('activity', $activity->value)
            ->overlapping($from->toDateString(), $to->toDateString())->orderBy('starts_on')->orderBy('id')->get();
        $month = $from->locale('fr')->translatedFormat('F Y');
        $lines = [];

        foreach ($plans->filter(fn (BillingPlan $p) => $p->formula->hasForfait()) as $plan) {
            // Dans l'ordre de création : les premières sont incluses, les suivantes au prix de leur destination
            $inForfait = $orders->where('billing_plan_id', $plan->id)->where('billing_basis', 'forfait')->values();
            $count = $inForfait->count();
            $units = $plan->formula->unitLabel();
            $where = 'dans les zones du forfait';

            $lines[] = $this->line('forfait', "Forfait {$plan->formula->label()} en {$plan->delivery_delay->label()} · {$month}", 1, $plan->monthly_fee);
            $lines[] = $this->line('included', ucfirst($units)." {$where} : {$count} ({$plan->included_quantity} inclus)", $count, 0);
            $inForfait->slice($plan->included_quantity)
                ->groupBy(fn (Order $o) => ($o->pricing_details['billing']['overage_label'] ?? 'Abidjan').'|'.($o->pricing_details['billing']['overage'] ?? $plan->overage_price))
                ->sortKeys()
                ->each(function (Collection $group, string $key) use (&$lines, $units) {
                    [$where, $price] = explode('|', $key, 2);
                    $lines[] = $this->line('overage', ucfirst($units)." au-delà du forfait · {$where}", $group->count(), (int) $price);
                });

            $heavy = $inForfait->filter(fn (Order $o) => (int) $o->billing_amount > 0);
            if ($heavy->isNotEmpty()) {
                $kg = (int) $heavy->sum(fn (Order $o) => (int) ($o->pricing_details['billing']['extra_kg'] ?? 0));
                $lines[] = $kg > 0 && $kg * $plan->extra_kg_price === (int) $heavy->sum('billing_amount')
                    ? $this->line('extra_kg', 'Kilogrammes au-delà de 5 kg', $kg, $plan->extra_kg_price)
                    : $this->line('extra_kg', 'Poids au-delà de 5 kg ('.$heavy->count().' envoi(s))', 1, (int) $heavy->sum('billing_amount'));
            }
        }

        // Envois à l'unité, regroupés par libellé et par prix
        $titles = ['cheque' => null, 'interior' => 'Intérieur', 'unit' => null, 'outside' => null];
        foreach ($titles as $basis => $prefix) {
            $orders->where('billing_basis', $basis)
                ->groupBy(fn (Order $o) => ($o->pricing_details['billing']['label'] ?? $basis).'|'.(int) $o->billing_amount)
                ->sortKeys()
                ->each(function (Collection $group) use (&$lines, $basis, $prefix) {
                    $first = $group->first();
                    $label = $first->pricing_details['billing']['label'] ?? $basis;
                    $lines[] = $this->line($basis, $prefix ? "{$prefix} · {$label}" : $label, $group->count(), (int) $first->billing_amount);
                });
        }

        // Suppléments du mois (demandes exceptionnelles), à part
        foreach ($this->supplements($merchant->company_id, $merchant->id, $activity, $from, $to)->get() as $supplement) {
            $lines[] = $this->line('supplement', 'Supplément · '.$supplement->label.' ('.$supplement->performed_on->format('d/m').')', $supplement->quantity, $supplement->unit_price);
        }

        return $lines;
    }

    /**
     * Suppléments d'un client (ou de tous) faits entre ces deux dates du mois.
     */
    public function supplements(int $companyId, ?int $merchantId, ?Activity $activity, CarbonImmutable $from, CarbonImmutable $to)
    {
        return ClientSupplement::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->when($merchantId, fn ($q) => $q->where('merchant_id', $merchantId))
            ->when($activity, fn ($q) => $q->where('activity', $activity->value))
            ->whereDate('performed_on', '>=', $from->toDateString())
            ->whereDate('performed_on', '<', $to->toDateString())
            ->orderBy('performed_on')->orderBy('id');
    }

    /**
     * Courses facturées au mois d'un client (ou de tous), créées entre ces deux instants.
     */
    public function orders(int $companyId, ?int $merchantId, ?Activity $activity, CarbonImmutable $from, CarbonImmutable $to)
    {
        return Order::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->when($merchantId, fn ($q) => $q->where('merchant_id', $merchantId))
            ->when($activity, fn ($q) => $q->where('activity', $activity->value))
            ->whereNotNull('billing_plan_id')
            ->whereNull('deleted_at')
            ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Rejected->value])
            ->where('created_at', '>=', $from->utc())
            ->where('created_at', '<', $to->utc())
            ->orderBy('created_at');
    }

    /**
     * Courses de la facture (annexe), dans l'ordre de création.
     */
    public function ordersOf(ClientInvoice $invoice)
    {
        $company = $invoice->company;
        [$from, $to] = $this->bounds($company, CarbonImmutable::parse($invoice->period));

        return $this->orders($company->id, $invoice->merchant_id, $invoice->activity, $from, $to)->with('deliveryZone:id,name,city');
    }

    public function addLine(ClientInvoice $invoice, string $label, int $quantity, int $unitPrice): ClientInvoice
    {
        $this->ensureDraft($invoice);
        $invoice->lines()->create([
            'kind' => 'adjustment',
            'label' => $label,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $quantity * $unitPrice,
            'manual' => true,
            'position' => 1000 + $invoice->lines()->count(),
        ]);

        return $this->refreshTotal($invoice);
    }

    public function removeLine(ClientInvoice $invoice, int $lineId): ClientInvoice
    {
        $this->ensureDraft($invoice);
        $invoice->lines()->where('manual', true)->whereKey($lineId)->firstOrFail()->delete();

        return $this->refreshTotal($invoice);
    }

    /**
     * Émission : numéro (DIS-AAAAMM-0001, MAD-AAAAMM-0001 : une série par activité), date et échéance. La facture ne change plus.
     */
    public function issue(ClientInvoice $invoice): ClientInvoice
    {
        $this->ensureDraft($invoice);
        if ($invoice->total <= 0) {
            throw new BusinessRuleException('Une facture à zéro ne s\'émet pas : ajoutez une ligne ou supprimez-la.', 'total');
        }

        return DB::transaction(function () use ($invoice) {
            // Une série par activité : DIS-AAAAMM-0001, MAD-AAAAMM-0001
            $prefix = config('platform.client_invoices.prefixes.'.$invoice->activity->value, $invoice->activity->invoicePrefix()).'-'.$invoice->period->format('Ym').'-';
            $last = ClientInvoice::withoutGlobalScopes()->where('company_id', $invoice->company_id)->where('number', 'like', $prefix.'%')->lockForUpdate()->pluck('number')
                ->map(fn ($n) => (int) substr($n, strlen($prefix)))->max() ?? 0;
            $today = CarbonImmutable::now($invoice->company->timezone ?: config('app.timezone'));

            $invoice->update([
                'number' => $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT),
                'status' => ClientInvoiceStatus::Issued,
                'issued_at' => $today->toDateString(),
                'due_at' => $today->addDays((int) config('platform.client_invoices.due_days', 15))->toDateString(),
            ]);

            // Suppléments repris par la facture : ils ne changent plus
            [$from, $to] = $this->bounds($invoice->company, CarbonImmutable::parse($invoice->period));
            $this->supplements($invoice->company_id, $invoice->merchant_id, $invoice->activity, $from, $to)
                ->whereNull('client_invoice_id')->update(['client_invoice_id' => $invoice->id]);

            return $invoice;
        });
    }

    /**
     * Paiement (partiel ou total) d'une facture émise.
     *
     * @param  array{amount: int, method: string, reference?: ?string, paid_on: string, notes?: ?string}  $data
     */
    public function pay(ClientInvoice $invoice, array $data, User $actor): ClientInvoice
    {
        if ($invoice->status !== ClientInvoiceStatus::Issued) {
            throw new BusinessRuleException('Seule une facture émise et pas encore payée reçoit un paiement.', 'amount');
        }
        if ($data['amount'] > $invoice->balance()) {
            throw new BusinessRuleException('Le paiement dépasse le reste à payer ('.Money::format($invoice->balance()).').', 'amount');
        }

        return DB::transaction(function () use ($invoice, $data, $actor) {
            ClientInvoicePayment::create([
                'company_id' => $invoice->company_id,
                'client_invoice_id' => $invoice->id,
                'amount' => $data['amount'],
                'method' => InvoicePaymentMethod::from($data['method']),
                'reference' => $data['reference'] ?? null,
                'paid_on' => $data['paid_on'],
                'recorded_by' => $actor->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $paid = (int) $invoice->payments()->sum('amount');
            $invoice->update([
                'amount_paid' => $paid,
                'status' => $paid >= $invoice->total ? ClientInvoiceStatus::Paid : ClientInvoiceStatus::Issued,
                'paid_at' => $paid >= $invoice->total ? now() : null,
            ]);

            return $invoice;
        });
    }

    /**
     * Annulation : un brouillon est supprimé ; une facture émise sans paiement garde son numéro, annulée.
     */
    public function void(ClientInvoice $invoice): ?ClientInvoice
    {
        if ($invoice->status === ClientInvoiceStatus::Draft) {
            $invoice->delete();

            return null;
        }
        if ($invoice->status !== ClientInvoiceStatus::Issued || $invoice->amount_paid > 0) {
            throw new BusinessRuleException('Seule une facture émise sans paiement peut être annulée.', 'status');
        }
        $invoice->update(['status' => ClientInvoiceStatus::Void]);
        // Suppléments libérés (à facturer autrement)
        ClientSupplement::withoutGlobalScopes()->where('client_invoice_id', $invoice->id)->update(['client_invoice_id' => null]);

        return $invoice;
    }

    /**
     * Début et fin (exclue) du mois, dans le fuseau de l'entreprise.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function bounds(Company $company, CarbonImmutable $month): array
    {
        $tz = $company->timezone ?: config('app.timezone');
        $from = CarbonImmutable::create($month->year, $month->month, 1, 0, 0, 0, $tz);

        return [$from, $from->addMonth()];
    }

    private function refreshTotal(ClientInvoice $invoice): ClientInvoice
    {
        $invoice->update(['total' => max(0, (int) $invoice->lines()->sum('amount'))]);

        return $invoice->fresh(['lines', 'payments', 'merchant']);
    }

    private function ensureDraft(ClientInvoice $invoice): void
    {
        if ($invoice->status !== ClientInvoiceStatus::Draft) {
            throw new BusinessRuleException('La facture est déjà émise : elle ne se modifie plus.', 'status');
        }
    }

    /** @return array{kind: string, label: string, quantity: int, unit_price: int, amount: int} */
    private function line(string $kind, string $label, int $quantity, int $unitPrice): array
    {
        return ['kind' => $kind, 'label' => mb_substr($label, 0, 255), 'quantity' => $quantity, 'unit_price' => $unitPrice, 'amount' => $quantity * $unitPrice];
    }
}
