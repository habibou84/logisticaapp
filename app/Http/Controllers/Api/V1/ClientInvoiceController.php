<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Activity;
use App\Enums\ClientInvoiceStatus;
use App\Enums\InvoicePaymentMethod;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\ClientCreditNote;
use App\Models\ClientInvoice;
use App\Models\Department;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\Departments\Departments;
use App\Services\Finance\ClientCreditNotes;
use App\Services\Finance\ClientInvoicing;
use App\Services\Finance\ClientSpace;
use App\Services\Finance\Fne;
use App\Support\InvoicePayments;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Factures du mois des clients sous formule : liste, détail (lignes, paiements, courses), calcul,
 * lignes à la main, émission, paiements, annulation. Le client consulte ses factures émises.
 */
class ClientInvoiceController extends Controller
{
    public function __construct(private readonly ClientInvoicing $invoicing, private readonly Departments $departments) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeView($request);
        $request->validate([
            'status' => ['nullable', Rule::enum(ClientInvoiceStatus::class)],
            'activity' => ['nullable', Rule::enum(Activity::class)],
            'merchant_id' => ['nullable', 'integer'],
            'period' => ['nullable', 'date_format:Y-m'],
        ]);

        $invoices = ClientInvoice::query()
            ->with(['merchant:id,business_name', 'department'])
            ->when($user->merchant_id, fn ($q) => $q->where('merchant_id', $user->merchant_id)->where(fn ($q) => $q->whereIn('status', [ClientInvoiceStatus::Issued->value, ClientInvoiceStatus::Paid->value])
                // Annulée par un avoir : le client garde la facture et son avoir
                ->orWhere('credited_amount', '>', 0)))
            // Employé limité à ses départements : leurs factures (une facture par département)
            ->when(($scope = $this->departments->scopeFor($user)) !== null, fn ($q) => $q->where(fn ($q) => $q->whereIn('department_id', $scope)->orWhereNull('department_id')))
            ->when(! $user->merchant_id && $request->filled('merchant_id'), fn ($q) => $q->where('merchant_id', $request->integer('merchant_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('activity'), fn ($q) => $q->where('activity', $request->string('activity')))
            ->when($request->filled('period'), fn ($q) => $q->whereDate('period', $request->string('period').'-01'))
            ->orderByDesc('period')->orderBy('id')
            ->limit(500)
            ->get();

        return response()->json([
            'data' => $invoices->map(fn (ClientInvoice $i) => $this->summary($i))->values(),
            'totals' => $user->merchant_id ? null : [
                'to_collect' => (int) $invoices->where('status', ClientInvoiceStatus::Issued)->sum(fn (ClientInvoice $i) => $i->balance()),
                'overdue' => (int) $invoices->filter->isOverdue()->sum(fn (ClientInvoice $i) => $i->balance()),
                'drafts' => $invoices->where('status', ClientInvoiceStatus::Draft)->count(),
            ],
            // Activités facturées au mois que l'utilisateur voit (filtre, calcul)
            'activities' => collect([Activity::Distribution, Activity::Mad])->filter(fn (Activity $a) => $user->seesActivity($a))
                ->map(fn (Activity $a) => ['value' => $a->value, 'label' => $a->label()])->values(),
        ]);
    }

    public function show(Request $request, ClientInvoice $clientInvoice): JsonResponse
    {
        $this->authorizeView($request, $clientInvoice);

        return response()->json(['data' => $this->detail($clientInvoice)]);
    }

    /** Calcule les brouillons du mois (AAAA-MM), pour tous les clients ou un seul */
    public function generate(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'merchant_id' => ['nullable', 'integer', Rule::exists('merchants', 'id')->where('company_id', $request->user()->company_id)],
            'activity' => [Rule::requiredIf($request->filled('merchant_id')), 'nullable', Rule::in([Activity::Distribution->value, Activity::Mad->value])],
        ], [], ['month' => 'mois', 'activity' => 'activité']);
        $month = CarbonImmutable::createFromFormat('Y-m-d', $data['month'].'-01');
        $user = $request->user();
        $activities = array_values(array_filter([Activity::Distribution->value, Activity::Mad->value], fn ($a) => $user->seesActivity($a)
            && (! isset($data['activity']) || $data['activity'] === $a)));
        abort_if($activities === [], 403, 'Vous ne gérez pas cette activité.');

        $count = isset($data['merchant_id'])
            ? (int) ($this->invoicing->compute(Merchant::findOrFail($data['merchant_id']), Activity::from($activities[0]), $month) !== null)
            : $this->invoicing->generate($user->company, $month, $activities);

        return response()->json(['data' => ['count' => $count]]);
    }

    public function refresh(Request $request, ClientInvoice $clientInvoice): JsonResponse
    {
        $this->authorizeManage($request);
        $invoice = $this->invoicing->compute($clientInvoice->merchant, $clientInvoice->activity, CarbonImmutable::parse($clientInvoice->period));
        // Facture d'un département : la même, recalculée
        $invoice = $clientInvoice->fresh() ?? $invoice;

        return response()->json(['data' => $invoice ? $this->detail($invoice) : null]);
    }

    public function addLine(Request $request, ClientInvoice $clientInvoice): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            // Négatif : remise
            'unit_price' => ['required', 'integer', 'min:-100000000', 'max:100000000'],
            // Facture ventilée : département de la ligne
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('merchant_id', $clientInvoice->merchant_id)],
        ], [], ['label' => 'libellé', 'quantity' => 'quantité', 'unit_price' => 'prix unitaire']);

        return response()->json(['data' => $this->detail($this->invoicing->addLine($clientInvoice, $data['label'], $data['quantity'], $data['unit_price'], $data['department_id'] ?? null))]);
    }

    public function removeLine(Request $request, ClientInvoice $clientInvoice, int $line): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json(['data' => $this->detail($this->invoicing->removeLine($clientInvoice, $line))]);
    }

    public function issue(Request $request, ClientInvoice $clientInvoice, ClientSpace $space): JsonResponse
    {
        $this->authorizeManage($request);
        $invoice = $this->invoicing->issue($clientInvoice);
        // Le client est prévenu (cloche ; push et WhatsApp selon ses réglages)
        $space->invoiceIssued($invoice);

        return response()->json(['data' => $this->detail($invoice)]);
    }

    public function pay(Request $request, ClientInvoice $clientInvoice): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::enum(InvoicePaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['amount' => 'montant', 'method' => 'moyen de paiement', 'paid_on' => 'date du paiement']);

        return response()->json(['data' => $this->detail($this->invoicing->pay($clientInvoice, $data, $request->user()))]);
    }

    public function void(Request $request, ClientInvoice $clientInvoice): JsonResponse
    {
        $this->authorizeManage($request);
        // Supprimer un brouillon : droit des factures ; annuler une facture émise : droit à part
        abort_unless($clientInvoice->status === ClientInvoiceStatus::Draft || $request->user()->can(Permission::InvoicesVoid->value), 403,
            'Annuler une facture émise demande le droit « Annuler une facture émise ».');
        $invoice = $this->invoicing->void($clientInvoice);

        return response()->json(['data' => $invoice ? $this->detail($invoice) : null]);
    }

    /**
     * Avoir sur la facture : total, partiel (lignes et quantités, montant libre).
     */
    public function creditNote(Request $request, ClientInvoice $clientInvoice, ClientCreditNotes $notes, ClientSpace $space): JsonResponse
    {
        $this->authorizeManage($request);
        abort_unless($request->user()->can(Permission::InvoicesVoid->value), 403, 'Faire un avoir demande le droit « Annuler une facture émise ou faire un avoir ».');
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'full' => ['required', 'boolean'],
            'lines' => ['array'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'extra' => ['nullable', 'array'],
            'extra.label' => ['required_with:extra', 'string', 'max:255'],
            'extra.amount' => ['required_with:extra', 'integer', 'min:0', 'max:100000000'],
        ], [], ['reason' => 'motif de l\'avoir', 'extra.label' => 'libellé', 'extra.amount' => 'montant']);
        $note = $notes->create($clientInvoice, $request->user(), $data['reason'], $data['full'], $data['lines'] ?? [], $data['extra'] ?? null);
        $space->creditNoteIssued($note);

        return response()->json(['data' => $this->detail($clientInvoice->fresh())], 201);
    }

    /**
     * Certifier (de nouveau) la facture et ses avoirs restés à certifier : FNE injoignable ou données corrigées.
     */
    public function certify(Request $request, ClientInvoice $clientInvoice, Fne $fne): JsonResponse
    {
        $this->authorizeManage($request);
        abort_unless($fne->enabled($clientInvoice->company), 422, 'La facture normalisée (FNE) n\'est pas activée (Réglages › Facturation et TVA).');
        abort_unless(in_array($clientInvoice->status, [ClientInvoiceStatus::Issued, ClientInvoiceStatus::Paid, ClientInvoiceStatus::Void], true), 422, 'Seule une facture émise se certifie.');
        if ($clientInvoice->fne_status !== 'certified') {
            $fne->certify($clientInvoice);
        }
        if ($clientInvoice->fresh()->fne_status === 'certified') {
            $clientInvoice->creditNotes()->whereIn('fne_status', ['pending', 'failed'])->get()->each(fn (ClientCreditNote $n) => $fne->refund($n));
        }

        return response()->json(['data' => $this->detail($clientInvoice->fresh())]);
    }

    /** @return array<string, mixed>|null */
    private static function fneData(ClientInvoice|ClientCreditNote $document): ?array
    {
        return $document->fne_status === null ? null : [
            'status' => $document->fne_status,
            'reference' => $document->fne_reference,
            'verification_url' => $document->fne_token,
            'error' => $document->fne_error,
            'certified_at' => $document->fne_certified_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function creditNoteData(ClientCreditNote $note): array
    {
        return [
            'id' => $note->id,
            'number' => $note->number,
            'issued_at' => $note->issued_at->toDateString(),
            'reason' => $note->reason,
            'vat_rate' => $note->vat_rate,
            'total_ht' => $note->total_ht,
            'vat_amount' => $note->vat_amount,
            'total' => $note->total,
            'refund_due' => $note->refund_due,
            'created_by' => $note->author?->name,
            'fne' => self::fneData($note),
            'lines' => $note->lines->map(fn ($l) => ['label' => $l->label, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'amount' => $l->amount])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function summary(ClientInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'activity' => $invoice->activity->value,
            'activity_label' => $invoice->activity->label(),
            'merchant' => $invoice->merchant ? ['id' => $invoice->merchant->id, 'business_name' => $invoice->merchant->business_name] : null,
            'period' => $invoice->period->format('Y-m'),
            'period_label' => ucfirst($invoice->period->locale('fr')->translatedFormat('F Y')),
            'status' => $invoice->status->value,
            'status_label' => $invoice->isOverdue() ? 'En retard' : ($invoice->status === ClientInvoiceStatus::Void && $invoice->credited_amount > 0 ? 'Annulée par avoir' : $invoice->status->label()),
            'is_overdue' => $invoice->isOverdue(),
            'total' => $invoice->total,
            // TVA : hors taxe, TVA, taux ; avoirs ; trop-perçu à rembourser
            'total_ht' => $invoice->total_ht,
            'vat_amount' => $invoice->vat_amount,
            'vat_rate' => $invoice->vat_rate,
            'credited_amount' => $invoice->credited_amount,
            'refund_due' => $invoice->refundDue(),
            // Facture normalisée électronique (DGI)
            'fne' => self::fneData($invoice),
            'amount_paid' => $invoice->amount_paid,
            'balance' => $invoice->balance(),
            'issued_at' => $invoice->issued_at?->toDateString(),
            'due_at' => $invoice->due_at?->toDateString(),
            // Une facture par département : celui de la facture
            'department' => $invoice->department_id ? $this->departmentBrief($invoice->loadMissing('department')->department) : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function departmentBrief(?Department $department): ?array
    {
        return $department ? ['id' => $department->id, 'name' => $department->name, 'label' => $department->label(), 'cost_center' => $department->cost_center] : null;
    }

    /**
     * Annexe de la facture pour Excel (CSV « ; ») : chaque course avec son département et son centre de coûts,
     * puis le total de chaque département.
     */
    public function export(Request $request, ClientInvoice $clientInvoice): Response
    {
        $this->authorizeView($request, $clientInvoice);
        $clientInvoice->loadMissing(['lines.department', 'merchant']);
        $rows = [['Département', 'Centre de coûts', 'Course', 'Date', 'Demandé par', 'Destinataire', 'Destination', 'Envoi', 'Nombre', 'Base', 'Montant']];
        foreach ($this->invoicing->ordersOf($clientInvoice)->get() as $o) {
            $rows[] = [
                $o->department?->name ?? '', $o->department?->cost_center ?? '', $o->tracking_code, $o->created_at->format('d/m/Y'),
                $o->requested_by ?? '', $o->toDepartment?->name ?? ($o->recipient_name ?? ''), $o->deliveryZone?->name ?? '',
                $o->shipment_type?->label() ?? '', (string) max(1, (int) $o->billing_units), $o->billing_basis ?? '',
                $o->billing_basis === 'forfait' ? '' : (string) (int) $o->billing_amount,
            ];
        }
        $rows[] = [];
        $rows[] = ['Total par département', 'Centre de coûts', '', '', '', '', '', '', '', '', 'Montant'];
        foreach ($clientInvoice->lines->groupBy(fn ($l) => (int) $l->department_id) as $group) {
            $department = $group->first()->department ?? $clientInvoice->loadMissing('department')->department;
            $rows[] = [$department?->name ?? 'Sans département', $department?->cost_center ?? '', '', '', '', '', '', '', '', '', (string) $group->sum('amount')];
        }
        if ($clientInvoice->vat_amount > 0) {
            $rows[] = ['Total hors taxe', '', '', '', '', '', '', '', '', '', (string) $clientInvoice->total_ht];
            $rows[] = ['TVA '.rtrim(rtrim(number_format($clientInvoice->vat_rate, 2, ',', ''), '0'), ',').' %', '', '', '', '', '', '', '', '', '', (string) $clientInvoice->vat_amount];
        }
        $rows[] = ['Total de la facture'.($clientInvoice->vat_amount > 0 ? ' TTC' : ''), '', '', '', '', '', '', '', '', '', (string) $clientInvoice->total];

        $csv = "\u{FEFF}".implode("\r\n", array_map(fn (array $r) => implode(';', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $r)), $rows));
        $name = 'facture-'.($clientInvoice->number ?: 'brouillon-'.$clientInvoice->id).'.csv';

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}\""]);
    }

    /** @return array<string, mixed> */
    private function detail(ClientInvoice $invoice): array
    {
        $invoice->loadMissing(['lines.department', 'payments.recorder:id,name', 'merchant', 'company', 'creditNotes.lines', 'creditNotes.author:id,name']);
        $merchant = $invoice->merchant;
        $company = $invoice->company;
        // Brouillon : identité fiscale du moment ; facture émise : celle de l'émission
        $fiscal = $invoice->client_snapshot ?? [
            'legal_name' => $merchant->legal_name ?: $merchant->business_name, 'ncc' => $merchant->ncc, 'rccm' => $merchant->rccm,
            'address' => $merchant->billing_address ?: $merchant->pickup_address,
        ];
        $credited = app(ClientCreditNotes::class)->creditedQuantities($invoice);

        return [
            ...$this->summary($invoice),
            'notes' => $invoice->notes,
            // Moyens de paiement de l'entreprise (Réglages › Entreprise), imprimés sur la facture
            'payment_methods' => InvoicePayments::forClients($invoice->company),
            'payment_info' => $invoice->company?->invoice_payment_info,
            'client' => [
                'business_name' => $merchant->business_name,
                'contact_name' => $merchant->contact_name,
                'phone' => $merchant->phone,
                'email' => $merchant->email,
                'address' => $fiscal['address'] ?? $merchant->pickup_address,
                'legal_name' => $fiscal['legal_name'] ?? null,
                'ncc' => $fiscal['ncc'] ?? null,
                'rccm' => $fiscal['rccm'] ?? null,
            ],
            // Émetteur : identité fiscale de l'entreprise (Réglages › Facturation et TVA)
            'issuer' => [
                'legal_name' => $company?->legal_name ?: $company?->name,
                'ncc' => $company?->ncc,
                'rccm' => $company?->rccm,
                'tax_regime' => $company?->tax_regime,
                'tax_office' => $company?->tax_office,
                'address' => $company?->address,
            ],
            'vat_basis' => $invoice->vat_basis,
            // FNE active : les avoirs reprennent des lignes de la facture certifiée
            'fne_enabled' => app(Fne::class)->enabled($company),
            'vat_exemption' => $invoice->vat_exemption,
            'credit_notes' => $invoice->creditNotes->map(fn (ClientCreditNote $n) => self::creditNoteData($n))->values(),
            'lines' => $invoice->lines->map(fn ($l) => [
                'id' => $l->id,
                'kind' => $l->kind,
                'label' => $l->label,
                'quantity' => $l->quantity,
                'unit_price' => $l->unit_price,
                'amount' => $l->amount,
                'manual' => $l->manual,
                'department_id' => $l->department_id,
                // Déjà repris sur un avoir
                'credited_quantity' => $credited[$l->id] ?? 0,
            ])->values(),
            // Facture ventilée : sous-total de chaque département (avec son centre de coûts)
            'departments' => $invoice->lines->whereNotNull('department_id')->isEmpty() ? [] : $invoice->lines->groupBy(fn ($l) => (int) $l->department_id)
                ->map(fn ($group, $id) => [
                    ...($this->departmentBrief($group->first()->department) ?? ['id' => null, 'name' => 'Sans département', 'label' => 'Sans département', 'cost_center' => null]),
                    'total' => (int) $group->sum('amount'),
                ])->values(),
            'payments' => $invoice->payments->map(fn ($p) => [
                'id' => $p->id,
                'amount' => $p->amount,
                'method' => $p->method->value,
                'method_label' => $p->method->label(),
                'reference' => $p->reference,
                'paid_on' => $p->paid_on->toDateString(),
                'recorded_by' => $p->recorder?->name,
            ])->values(),
            // Annexe : les courses du mois
            'orders' => $this->invoicing->ordersOf($invoice)->get()->map(fn (Order $o) => [
                'id' => $o->id,
                'tracking_code' => $o->tracking_code,
                'created_at' => $o->created_at,
                'recipient_name' => $o->recipient_name,
                'destination' => $o->deliveryZone?->name,
                'shipment_type' => $o->shipment_type?->label(),
                'delivery_delay' => $o->delivery_delay?->label(),
                'weight_kg' => $o->weight_kg,
                'status' => $o->status->value,
                'status_label' => $o->status->label(),
                'basis' => $o->billing_basis,
                'amount' => $o->billing_basis === 'forfait' ? null : $o->billing_amount,
                'units' => max(1, (int) $o->billing_units),
                'department' => $o->department?->label(),
                'requested_by' => $o->requested_by,
            ])->values(),
        ];
    }

    private function authorizeView(Request $request, ?ClientInvoice $invoice = null): void
    {
        $user = $request->user();
        if ($user->merchant_id !== null) {
            abort_unless($invoice === null || ($invoice->merchant_id === $user->merchant_id
                && (in_array($invoice->status, [ClientInvoiceStatus::Issued, ClientInvoiceStatus::Paid], true) || $invoice->credited_amount > 0)), 404);
            // Facture d'un autre département
            $scope = $this->departments->scopeFor($user);
            abort_if($invoice?->department_id !== null && $scope !== null && ! in_array($invoice->department_id, $scope, true), 404);
            abort_unless($user->can(Permission::FinanceView->value), 403);

            return;
        }
        abort_unless($user->can(Permission::InvoicesView->value), 403);
        abort_if($invoice !== null && ! $user->seesActivity($invoice->activity), 404);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->merchant_id === null && $request->user()->can(Permission::InvoicesManage->value), 403);
        $invoice = $request->route('clientInvoice');
        abort_if($invoice instanceof ClientInvoice && ! $request->user()->seesActivity($invoice->activity), 404);
    }
}
