<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformInvoice;
use App\Models\PlatformPayment;
use App\Models\PlatformSetting;
use App\Services\Platform\Billing;
use App\Services\Platform\CompanySignup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Console du super administrateur : factures des entreprises, paiements, revenus, réglages.
 */
class ConsoleBillingController extends Controller
{
    public function __construct(private readonly Billing $billing) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in([PlatformInvoice::OPEN, PlatformInvoice::PAID, PlatformInvoice::VOID, 'overdue'])],
            'company_id' => ['nullable', 'integer'],
            'period' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $invoices = PlatformInvoice::query()->with('company:id,name,slug')
            ->when($request->string('status')->toString() === 'overdue', fn ($q) => $q->unpaid()->where('due_at', '<', now()->toDateString()))
            ->when(in_array($request->input('status'), [PlatformInvoice::OPEN, PlatformInvoice::PAID, PlatformInvoice::VOID], true), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('period'), fn ($q) => $q->where('period', $request->string('period')))
            ->orderByDesc('period')->orderByDesc('id')
            ->limit(300)->get();

        return response()->json([
            'data' => $invoices->map(fn (PlatformInvoice $i) => self::invoiceData($i))->values(),
            'meta' => [
                'totals' => $this->billing->totals(now()->format('Y-m')),
                'methods' => PlatformPayment::METHODS,
                'previous_period' => now()->subMonthNoOverflow()->format('Y-m'),
            ],
        ]);
    }

    public function show(PlatformInvoice $invoice): JsonResponse
    {
        return response()->json(['data' => [
            ...self::invoiceData($invoice->load(['company', 'payments.recorder']), withPayments: true),
            ...self::documentSettings(),
        ]]);
    }

    /**
     * Factures d'un mois (rattrapage ou essai) ; celles déjà faites ne changent pas.
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/', 'before_or_equal:'.now()->format('Y-m')]], [], ['period' => 'mois']);

        $created = $this->billing->generate($data['period']);

        return response()->json(['data' => ['created' => $created->count()]], 201);
    }

    public function pay(Request $request, PlatformInvoice $invoice): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::in(array_keys(PlatformPayment::METHODS))],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['amount' => 'montant', 'method' => 'moyen de paiement', 'paid_on' => 'date du paiement']);

        $this->billing->recordPayment($invoice, $data, $request->user());

        return $this->show($invoice->refresh())->setStatusCode(201);
    }

    public function void(Request $request, PlatformInvoice $invoice): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->billing->void($invoice, $data['reason'] ?? null);

        return $this->show($invoice->refresh());
    }

    public function settings(): JsonResponse
    {
        return response()->json(['data' => [
            ...self::documentSettings(),
            // Inscription des entreprises en libre-service (ouverte par défaut)
            'company_signup' => CompanySignup::enabledInConsole(),
        ]]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_instructions' => ['nullable', 'string', 'max:2000'],
            'invoice_issuer' => ['nullable', 'string', 'max:1000'],
            'company_signup' => ['sometimes', 'boolean'],
        ], [], ['payment_instructions' => 'instructions de paiement', 'invoice_issuer' => 'émetteur des factures', 'company_signup' => 'inscription en ligne']);

        if (array_key_exists('company_signup', $data)) {
            PlatformSetting::put('company_signup', $data['company_signup'] ? '1' : '0');
        }

        foreach (['payment_instructions', 'invoice_issuer'] as $key) {
            if (array_key_exists($key, $data)) {
                PlatformSetting::put($key, $data[$key]);
            }
        }

        return $this->settings();
    }

    /**
     * Mentions des factures : émetteur (nom, adresse, contacts) et moyens de paiement.
     *
     * @return array{payment_instructions: ?string, invoice_issuer: ?string}
     */
    public static function documentSettings(): array
    {
        return [
            'payment_instructions' => PlatformSetting::get('payment_instructions'),
            'invoice_issuer' => PlatformSetting::get('invoice_issuer'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function invoiceData(PlatformInvoice $i, bool $withPayments = false): array
    {
        return [
            'id' => $i->id,
            'number' => $i->number,
            'company' => $i->relationLoaded('company') && $i->company ? ['id' => $i->company->id, 'name' => $i->company->name, 'slug' => $i->company->slug] : null,
            'period' => $i->period,
            'period_start' => $i->period_start->toDateString(),
            'period_end' => $i->period_end->toDateString(),
            'plan_name' => $i->plan_name,
            'base_amount' => $i->base_amount,
            'included_orders' => $i->included_orders,
            'orders_count' => $i->orders_count,
            'overage_orders' => $i->overage_orders,
            'overage_per_order' => $i->overage_per_order,
            'overage_amount' => $i->overage_amount,
            'total' => $i->total,
            'amount_paid' => $i->amount_paid,
            'balance' => $i->balance(),
            'status' => $i->status,
            'days_late' => $i->relationLoaded('company') ? $i->daysLate() : null,
            'issued_at' => $i->issued_at->toDateString(),
            'due_at' => $i->due_at->toDateString(),
            'paid_at' => $i->paid_at,
            'notes' => $i->notes,
            'payments' => $withPayments ? $i->payments->map(fn (PlatformPayment $p) => [
                'id' => $p->id,
                'amount' => $p->amount,
                'method' => $p->method,
                'method_label' => PlatformPayment::METHODS[$p->method] ?? $p->method,
                'reference' => $p->reference,
                'paid_on' => $p->paid_on->toDateString(),
                'recorded_by' => $p->recorder?->name,
                'notes' => $p->notes,
            ])->values() : null,
        ];
    }
}
