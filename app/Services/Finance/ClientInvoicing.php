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
use App\Models\Department;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Services\Departments\DepartmentSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
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
     * facture déjà émise. Client à départements (réglage) : lignes ventilées par département sur une
     * facture, ou une facture par département (la facture du département principal est renvoyée).
     */
    public function compute(Merchant $merchant, Activity $activity, CarbonImmutable $month): ?ClientInvoice
    {
        $company = $merchant->company;
        [$from, $to] = $this->bounds($company, $month);
        $mode = $this->departmentMode($merchant);

        if ($mode === 'none') {
            return $this->store($merchant, $activity, $from, null, $this->lines($merchant, $activity, $from, $to));
        }

        $byDepartment = $this->departmentLines($merchant, $activity, $from, $to);
        if ($mode === 'detailed') {
            $lines = [];
            foreach ($byDepartment as $key => $part) {
                foreach ($part as $line) {
                    $lines[] = [...$line, 'department_id' => $key ?: null];
                }
            }

            return $this->store($merchant, $activity, $from, null, $lines);
        }

        // Une facture par département ; celles d'un département sans rien ce mois-ci disparaissent (brouillons)
        $main = null;
        $kept = [];
        foreach ($byDepartment as $key => $part) {
            $invoice = $this->store($merchant, $activity, $from, $key ?: null, $part);
            $kept[] = $key ?: null;
            if ($invoice !== null && ($main === null || $key === $this->mainDepartment($merchant))) {
                $main = $invoice;
            }
        }
        ClientInvoice::withoutGlobalScopes()->where('merchant_id', $merchant->id)->where('activity', $activity->value)
            ->whereDate('period', $from->toDateString())->where('status', ClientInvoiceStatus::Draft->value)->get()
            ->reject(fn (ClientInvoice $i) => in_array($i->department_id, $kept, true) || $i->lines()->where('manual', true)->exists())
            ->each->delete();

        return $main;
    }

    /**
     * Brouillon d'un client (et d'un département) pour le mois : lignes calculées remplacées, lignes ajoutées
     * à la main gardées. Null : rien à facturer, ou facture déjà émise.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function store(Merchant $merchant, Activity $activity, CarbonImmutable $from, ?int $departmentId, array $lines): ?ClientInvoice
    {
        $company = $merchant->company;
        $period = $from->startOfMonth()->toDateString();

        $invoice = ClientInvoice::forCompany($company->id)->withoutGlobalScopes()->where('company_id', $company->id)
            ->where('merchant_id', $merchant->id)->where('activity', $activity->value)->whereDate('period', $period)
            ->where(fn ($q) => $departmentId === null ? $q->whereNull('department_id') : $q->where('department_id', $departmentId))->first();
        if ($invoice !== null && $invoice->status !== ClientInvoiceStatus::Draft) {
            return null;
        }

        if ($lines === [] && ($invoice === null || ! $invoice->lines()->where('manual', true)->exists())) {
            $invoice?->delete();

            return null;
        }

        return DB::transaction(function () use ($company, $merchant, $activity, $period, $departmentId, $invoice, $lines) {
            $invoice ??= ClientInvoice::create([
                'company_id' => $company->id,
                'merchant_id' => $merchant->id,
                'activity' => $activity,
                'period' => $period,
                'department_id' => $departmentId,
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
     * Présentation de la facture d'un client à départements : detailed (ventilée), split (une par
     * département) ou none (sans détail, ou client sans département).
     */
    public function departmentMode(Merchant $merchant): string
    {
        $settings = app(DepartmentSettings::class)->for($merchant);
        if (! $settings['enabled'] || ! Department::withoutGlobalScopes()->where('merchant_id', $merchant->id)->exists()) {
            return 'none';
        }

        return $settings['invoice_mode'];
    }

    /** Département principal du client (0 : aucun) */
    private function mainDepartment(Merchant $merchant): int
    {
        return (int) Department::withoutGlobalScopes()->where('merchant_id', $merchant->id)->where('is_main', true)->value('id');
    }

    /**
     * Lignes du mois de chaque département (clé 0 : courses sans département), forfait réparti selon les envois
     * de chacun ou payé par le département principal ; lots à prix global et suppléments au principal.
     *
     * @return array<int, list<array{kind: string, label: string, quantity: int, unit_price: int, amount: int}>>
     */
    public function departmentLines(Merchant $merchant, Activity $activity, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $main = $this->mainDepartment($merchant);
        $keys = $this->orders($merchant->company_id, $merchant->id, $activity, $from, $to)->pluck('department_id')
            ->map(fn ($id) => (int) $id)->push($main)->unique()->sort()->values()->all();
        // Département principal d'abord, courses sans département à la fin
        usort($keys, fn (int $a, int $b) => [$a !== $main, $a === 0, $a] <=> [$b !== $main, $b === 0, $b]);
        $split = ['keys' => $keys, 'main' => $main, 'mode' => app(DepartmentSettings::class)->for($merchant)['forfait_split']];

        $out = [];
        foreach ($keys as $key) {
            $lines = $this->lines($merchant, $activity, $from, $to, [...$split, 'key' => $key]);
            if ($lines !== []) {
                $out[$key] = $lines;
            }
        }

        return $out;
    }

    /**
     * Lignes calculées du mois (d'un seul département si $split le dit : clé, département principal, mode de
     * partage du forfait, tous les départements du mois).
     *
     * @param  array{key: int, main: int, mode: string, keys: list<int>}|null  $split
     * @return list<array{kind: string, label: string, quantity: int, unit_price: int, amount: int}>
     */
    public function lines(Merchant $merchant, Activity $activity, CarbonImmutable $from, CarbonImmutable $to, ?array $split = null): array
    {
        $orders = $this->orders($merchant->company_id, $merchant->id, $activity, $from, $to)->get();
        $plans = BillingPlan::forCompany($merchant->company_id)->where('merchant_id', $merchant->id)->where('activity', $activity->value)
            ->overlapping($from->toDateString(), $to->toDateString())->orderBy('starts_on')->orderBy('id')->get();
        $month = $from->locale('fr')->translatedFormat('F Y');
        $lines = [];
        $mine = fn (Order $o) => $split === null || (int) $o->department_id === $split['key'];
        $isMain = $split === null || $split['key'] === $split['main'];
        $units = fn (Order $o) => max(1, (int) $o->billing_units);

        foreach ($plans->filter(fn (BillingPlan $p) => $p->formula->hasForfait()) as $plan) {
            // Dans l'ordre de création : les premières sont incluses, les suivantes au prix de leur destination
            $inForfait = $orders->where('billing_plan_id', $plan->id)->where('billing_basis', 'forfait')->values();
            // Course de plusieurs plis facturés par pli : chacun compte dans le forfait
            $total = (int) $inForfait->sum($units);
            $count = (int) $inForfait->filter($mine)->sum($units);
            $label = $plan->formula->unitLabel();
            $where = 'dans les zones du forfait';

            $fee = $split === null ? (int) $plan->monthly_fee : $this->forfaitShare((int) $plan->monthly_fee, $inForfait, $split);
            if ($split === null || $fee > 0 || $isMain) {
                $part = $split !== null && $fee !== (int) $plan->monthly_fee && $plan->monthly_fee > 0 ? ' (part : '.round(100 * $fee / $plan->monthly_fee).' %)' : '';
                $lines[] = $this->line('forfait', "Forfait {$plan->formula->label()} en {$plan->delivery_delay->label()} · {$month}{$part}", 1, $fee);
            }
            if ($split === null || $count > 0) {
                $lines[] = $this->line('included', ucfirst($label)." {$where} : {$count}".($split === null ? '' : " sur {$total}")." ({$plan->included_quantity} inclus)", $count, 0);
            }
            $inForfait->flatMap(fn (Order $o) => array_fill(0, $units($o), $o))->slice($plan->included_quantity)->filter($mine)
                ->groupBy(fn (Order $o) => ($o->pricing_details['billing']['overage_label'] ?? 'Abidjan').'|'.($o->pricing_details['billing']['overage'] ?? $plan->overage_price))
                ->sortKeys()
                ->each(function (BaseCollection $group, string $key) use (&$lines, $label) {
                    [$where, $price] = explode('|', $key, 2);
                    $lines[] = $this->line('overage', ucfirst($label)." au-delà du forfait · {$where}", $group->count(), (int) $price);
                });

            $heavy = $inForfait->filter($mine)->filter(fn (Order $o) => (int) $o->billing_amount > 0);
            if ($heavy->isNotEmpty()) {
                $kg = (int) $heavy->sum(fn (Order $o) => (int) ($o->pricing_details['billing']['extra_kg'] ?? 0));
                $lines[] = $kg > 0 && $kg * $plan->extra_kg_price === (int) $heavy->sum('billing_amount')
                    ? $this->line('extra_kg', 'Kilogrammes au-delà de 5 kg', $kg, $plan->extra_kg_price)
                    : $this->line('extra_kg', 'Poids au-delà de 5 kg ('.$heavy->count().' envoi(s))', 1, (int) $heavy->sum('billing_amount'));
            }
        }

        // Lots ponctuels à prix global : facturés le mois où ils commencent (au département principal)
        if ($isMain) {
            BillingPlan::forCompany($merchant->company_id)->where('merchant_id', $merchant->id)->where('activity', $activity->value)->lots()
                ->whereNotNull('lot_price')->whereDate('starts_on', '>=', $from->toDateString())->whereDate('starts_on', '<', $to->toDateString())
                ->orderBy('starts_on')->orderBy('id')->get()
                ->each(function (BillingPlan $lot) use (&$lines) {
                    $lines[] = $this->line('lot', "{$lot->lotName()} : {$lot->lot_quantity} colis en {$lot->delivery_delay->label()} (prix du lot)", 1, (int) $lot->lot_price);
                });
        }

        // Envois à l'unité, regroupés par libellé et par prix (colis des lots compris)
        $titles = ['cheque' => null, 'interior' => 'Intérieur', 'unit' => null, 'outside' => null, 'lot' => null, 'lot_extra' => null];
        foreach ($titles as $basis => $prefix) {
            // Plusieurs plis ou chèques sur une course : comptés pli par pli, au prix d'un pli
            $orders->where('billing_basis', $basis)->filter($mine)
                ->groupBy(fn (Order $o) => ($o->pricing_details['billing']['label'] ?? $basis).'|'.$this->unitPrice($o)[1])
                ->sortKeys()
                ->each(function (Collection $group) use (&$lines, $basis, $prefix) {
                    $first = $group->first();
                    $label = $first->pricing_details['billing']['label'] ?? $basis;
                    // Colis compris dans le prix global de leur lot : rappelés, sans montant
                    $kind = $basis === 'lot' && (int) $first->billing_amount === 0 ? 'included' : $basis;
                    $quantity = (int) $group->sum(fn (Order $o) => $this->unitPrice($o)[0]);
                    $lines[] = $this->line($kind, $prefix ? "{$prefix} · {$label}" : $label, $quantity, $this->unitPrice($first)[1]);
                });
        }

        // Suppléments du mois (demandes exceptionnelles), à part (au département principal)
        if ($isMain) {
            foreach ($this->supplements($merchant->company_id, $merchant->id, $activity, $from, $to)->get() as $supplement) {
                $lines[] = $this->line('supplement', 'Supplément · '.$supplement->label.' ('.$supplement->performed_on->format('d/m').')', $supplement->quantity, $supplement->unit_price);
            }
        }

        return $lines;
    }

    /**
     * Part du forfait d'un département : selon ses envois dans le forfait (le reste des arrondis au principal),
     * ou tout au principal (réglage, ou aucun envoi à répartir).
     *
     * @param  Collection<int, Order>  $inForfait
     * @param  array{key: int, main: int, mode: string, keys: list<int>}  $split
     */
    private function forfaitShare(int $fee, Collection $inForfait, array $split): int
    {
        $total = (int) $inForfait->sum(fn (Order $o) => max(1, (int) $o->billing_units));
        if ($split['mode'] === 'main' || $total === 0) {
            return $split['key'] === $split['main'] ? $fee : 0;
        }
        $shares = [];
        foreach ($split['keys'] as $key) {
            $count = (int) $inForfait->filter(fn (Order $o) => (int) $o->department_id === $key)->sum(fn (Order $o) => max(1, (int) $o->billing_units));
            $shares[$key] = intdiv($fee * $count, $total);
        }
        $shares[$split['main']] = ($shares[$split['main']] ?? 0) + $fee - array_sum($shares);

        return $shares[$split['key']] ?? 0;
    }

    /**
     * Quantité et prix unitaire d'une course sur la facture : ses plis (ou chèques) au prix d'un pli, ou une
     * fois son montant (prix qui ne se divise pas : kilos en plus).
     *
     * @return array{0: int, 1: int}
     */
    private function unitPrice(Order $order): array
    {
        $units = max(1, (int) $order->billing_units);
        $amount = (int) $order->billing_amount;

        return $units > 1 && $amount % $units === 0 ? [$units, intdiv($amount, $units)] : [1, $amount];
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

        return $this->orders($company->id, $invoice->merchant_id, $invoice->activity, $from, $to)->with(['deliveryZone:id,name,city', 'department', 'toDepartment'])
            // Facture d'un département : ses courses
            ->when($invoice->department_id !== null, fn ($q) => $q->where('department_id', $invoice->department_id));
    }

    public function addLine(ClientInvoice $invoice, string $label, int $quantity, int $unitPrice, ?int $departmentId = null): ClientInvoice
    {
        $this->ensureDraft($invoice);
        $invoice->lines()->create([
            'department_id' => $departmentId ?? $invoice->department_id,
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
        // TVA et exonération du jour de l'émission
        $invoice = $this->refreshTotal($invoice);
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
                'client_snapshot' => $this->clientSnapshot($invoice->merchant),
            ]);

            // Suppléments repris par la facture : ils ne changent plus (facture par département : celle du principal)
            if ($invoice->lines()->where('kind', 'supplement')->exists()) {
                [$from, $to] = $this->bounds($invoice->company, CarbonImmutable::parse($invoice->period));
                $this->supplements($invoice->company_id, $invoice->merchant_id, $invoice->activity, $from, $to)
                    ->whereNull('client_invoice_id')->update(['client_invoice_id' => $invoice->id]);
            }

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

            $invoice->update(['amount_paid' => (int) $invoice->payments()->sum('amount')]);
            $this->settle($invoice);

            return $invoice;
        });
    }

    /**
     * Statut selon ce qui reste dû : payée quand paiements et avoirs couvrent le total ; annulée quand un
     * avoir couvre tout et que rien n'avait été payé.
     */
    public function settle(ClientInvoice $invoice): ClientInvoice
    {
        if (! in_array($invoice->status, [ClientInvoiceStatus::Issued, ClientInvoiceStatus::Paid], true)) {
            return $invoice;
        }
        $settled = $invoice->amount_paid + $invoice->credited_amount >= $invoice->total;
        $status = match (true) {
            ! $settled => ClientInvoiceStatus::Issued,
            $invoice->credited_amount >= $invoice->total && $invoice->amount_paid === 0 => ClientInvoiceStatus::Void,
            default => ClientInvoiceStatus::Paid,
        };
        $invoice->update(['status' => $status, 'paid_at' => $status === ClientInvoiceStatus::Paid ? ($invoice->paid_at ?? now()) : null]);
        if ($status === ClientInvoiceStatus::Void) {
            $this->releaseSupplements($invoice);
        }

        return $invoice;
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
        if ($invoice->credited_amount > 0) {
            throw new BusinessRuleException('Un avoir a déjà été fait sur cette facture : faites un avoir pour le reste.', 'status');
        }
        $invoice->update(['status' => ClientInvoiceStatus::Void]);
        $this->releaseSupplements($invoice);

        return $invoice;
    }

    /** Suppléments libérés (à facturer autrement) */
    private function releaseSupplements(ClientInvoice $invoice): void
    {
        ClientSupplement::withoutGlobalScopes()->where('client_invoice_id', $invoice->id)->update(['client_invoice_id' => null]);
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

    /**
     * Totaux du brouillon : TVA du moment (réglage de l'entreprise, exonération du client) sur la somme des
     * lignes, saisies hors taxe ou toutes taxes comprises.
     */
    private function refreshTotal(ClientInvoice $invoice): ClientInvoice
    {
        $terms = app(Vat::class)->terms($invoice->merchant()->withoutGlobalScopes()->with('company')->first());
        $invoice->update([...$terms, ...Vat::split((int) $invoice->lines()->sum('amount'), $terms['vat_rate'], $terms['vat_basis'])]);

        return $invoice->fresh(['lines', 'payments', 'merchant']);
    }

    /** Identité fiscale du client imprimée sur la facture, figée à l'émission */
    private function clientSnapshot(Merchant $merchant): array
    {
        return [
            'legal_name' => $merchant->legal_name ?: $merchant->business_name,
            'ncc' => $merchant->ncc,
            'rccm' => $merchant->rccm,
            'address' => $merchant->billing_address ?: $merchant->pickup_address,
        ];
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
