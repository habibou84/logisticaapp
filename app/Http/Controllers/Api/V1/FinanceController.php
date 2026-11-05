<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\WhatsAppTemplate;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\CashCollection;
use App\Models\CashLog;
use App\Models\ClientInvoicePayment;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierAdvance;
use App\Models\CourierEarning;
use App\Models\CourierPayout;
use App\Models\CourierRemittance;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\MerchantPaymentMethod;
use App\Models\MerchantPayout;
use App\Models\OrderExpense;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Couriers\ParcelCustody;
use App\Services\Finance\CashAlerts;
use App\Services\Finance\CashDesk;
use App\Services\Finance\CashJournal;
use App\Services\Finance\CourierCash;
use App\Services\Finance\CourierPay;
use App\Services\Finance\CourierPayroll;
use App\Services\Finance\MerchantPayouts;
use App\Services\Finance\PayBonuses;
use App\Services\Messaging\Messenger;
use App\Services\Messaging\TemplateAutoSubmit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Caisse de l'entreprise de livraison : versements des livreurs, grand livre et
 * reversements des clients, paie des livreurs. Les marchands consultent leur
 * propre grand livre et leurs reversements.
 */
class FinanceController extends Controller
{
    public function __construct(
        private readonly CashDesk $cashDesk,
        private readonly MerchantPayouts $payouts,
        private readonly CourierPayroll $payroll,
        private readonly CourierCash $courierCash,
        private readonly ParcelCustody $custody,
        private readonly CashJournal $journal,
        private readonly CashAlerts $alerts,
    ) {}

    // ───────────── Argent chez les livreurs et versements ─────────────

    public function cash(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        // Agrégats groupés par livreur (pas de requête par ligne)
        $cash = $this->courierCash->balances();
        $unpaid = CourierEarning::whereNull('payout_id')->groupBy('courier_id')
            ->selectRaw('courier_id, SUM(amount) AS total')->pluck('total', 'courier_id');

        $parcels = ParcelCustody::heldQuery()->groupBy('held_by_courier_id')
            ->selectRaw('held_by_courier_id, COUNT(*) AS n')->pluck('n', 'held_by_courier_id');

        $company = $request->user()->company;
        $couriers = Courier::with('user')->get()->map(fn (Courier $c) => [
            'courier_id' => $c->id,
            'name' => $c->user?->name,
            'phone' => $c->user?->phone,
            'active' => $c->user?->status === 'active',
            // À surveiller : argent gardé trop longtemps ou au-delà du plafond de l'entreprise
            'watch' => isset($cash[$c->id]) ? $this->alerts->reason($company, $cash[$c->id]) : null,
            'cash_in_hand' => $cash[$c->id]['due'] ?? 0,
            'collected' => $cash[$c->id]['collected'] ?? 0,
            'advances' => $cash[$c->id]['advances'] ?? 0,
            'expenses' => $cash[$c->id]['expenses'] ?? 0,
            'unpaid' => (int) ($unpaid[$c->id] ?? 0),
            'pending_collections' => $cash[$c->id]['items'] ?? 0,
            'oldest_collected_at' => $cash[$c->id]['oldest'] ?? null,
            'parcels_in_hand' => (int) ($parcels[$c->id] ?? 0),
        ])
            // Montant absolu : un livreur à qui la caisse doit des frais avancés reste en tête de liste
            ->sortByDesc(fn ($c) => abs($c['cash_in_hand']))->values();

        return response()->json([
            'data' => $couriers,
            'totals' => [
                // Argent que les livreurs doivent remettre ; ce que la caisse leur doit est compté à part
                'cash_in_hands' => $couriers->where('cash_in_hand', '>', 0)->sum('cash_in_hand'),
                'owed_to_couriers' => -$couriers->where('cash_in_hand', '<', 0)->sum('cash_in_hand'),
                'collected_today' => (int) CashCollection::whereDate('collected_at', today())->sum('amount_collected'),
                // Reçu des livreurs aujourd'hui (remboursements de frais et versements annulés à part)
                'received_today' => (int) CourierRemittance::whereDate('received_at', today())->whereNull('cancelled_at')->where('amount_received', '>', 0)->sum('amount_received'),
                'watched' => $couriers->whereNotNull('watch')->count(),
            ],
            'alert' => ['hours' => $company->cash_alert_hours, 'amount' => $company->cash_alert_amount],
            // Reçu et rappel WhatsApp : modèles soumis à Meta tout seuls s'ils ne l'ont pas encore été
            'whatsapp_templates' => app(TemplateAutoSubmit::class)->ensure($company->id, [WhatsAppTemplate::CashReceipt, WhatsAppTemplate::CashReminder]),
        ]);
    }

    public function collections(Request $request, Courier $courier): JsonResponse
    {
        $this->authorizeStaff($request);

        $collections = $courier->collections()
            ->when($request->boolean('pending', true), fn ($q) => $q->inCourierHands())
            ->with('order:id,tracking_code,recipient_name,merchant_id')
            ->orderBy('collected_at')
            ->get()
            ->map(fn (CashCollection $c) => $this->collectionData($c));

        return response()->json([
            'data' => $collections,
            ...$this->courierExtras($courier),
        ]);
    }

    /**
     * Avance remise par la caisse au livreur (frais de gare, transport…) ; ce qu'il
     * n'a pas dépensé revient à la caisse lors de son versement.
     */
    public function storeAdvance(Request $request, Courier $courier): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:10000000'],
            'reason' => ['required', 'string', 'max:255'],
            'order_id' => ['nullable', 'integer', Rule::exists('orders', 'id')->where('company_id', $courier->company_id)],
        ], [], ['amount' => 'montant', 'reason' => 'motif']);

        $advance = $courier->advances()->create([
            ...$data,
            'company_id' => $courier->company_id,
            'given_by' => $request->user()->id,
            'given_at' => now(),
        ]);

        $this->journal->record($request->user(), $courier->company_id, 'advance', ($courier->user?->name ?? 'Livreur').' · '.$data['reason'],
            ['courier_id' => $courier->id, 'amount' => $advance->amount]);

        return response()->json(['data' => $this->advanceData($advance->load('order:id,tracking_code'))], 201);
    }

    /** Annule une avance donnée par erreur (le jour même, avant le versement) */
    public function cancelAdvance(Request $request, CourierAdvance $advance): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'raison']);

        $this->cashDesk->cancelAdvance($request->user(), $advance, $data['reason']);
        $this->journal->record($request->user(), $advance->company_id, 'advance_cancelled',
            ($advance->courier?->user?->name ?? 'Livreur').' · '.$advance->reason.' · « '.$data['reason'].' »',
            ['courier_id' => $advance->courier_id, 'amount' => -$advance->amount]);

        return response()->json(['data' => $this->advanceData($advance)]);
    }

    public function storeRemittance(Request $request): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);

        $data = $request->validate([
            'courier_id' => ['required', 'integer', Rule::exists('couriers', 'id')->where('company_id', $request->user()->company_id)],
            // Négatif : la caisse rembourse au livreur des frais qu'il a avancés
            'amount_received' => ['required', 'integer', 'min:-10000000', 'max:100000000'],
            'collection_ids' => ['nullable', 'array'],
            'collection_ids.*' => ['integer'],
            // Colis non livrés que le livreur rend au dépôt pendant le point
            'returned_order_ids' => ['nullable', 'array'],
            'returned_order_ids.*' => ['integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['amount_received' => 'montant reçu', 'courier_id' => 'livreur']);

        $courier = Courier::findOrFail($data['courier_id']);

        // Pas de point tant qu'une course est « En chemin » : le livreur doit d'abord la clôturer
        $onTheRoad = $this->custody->onTheRoad($courier);
        if ($onTheRoad->isNotEmpty()) {
            throw new BusinessRuleException(
                $onTheRoad->count().' course(s) encore « En chemin » ('.$onTheRoad->pluck('tracking_code')->implode(', ').'). '
                .'Le livreur doit indiquer « livré » ou « échec » avant le point de caisse.',
                'courier_id',
            );
        }

        $remittance = DB::transaction(function () use ($request, $courier, $data) {
            $received = $this->custody->receive($request->user(), $courier, $data['returned_order_ids'] ?? []);

            $remittance = $this->cashDesk->remit(
                $request->user(),
                $courier,
                $data['amount_received'],
                $data['collection_ids'] ?? null,
                $data['notes'] ?? null,
            );

            // Trace des colis rendus et de ceux que le livreur garde encore
            $kept = $this->custody->held($courier);
            if ($received->isNotEmpty() || $kept->isNotEmpty()) {
                $remittance->update(['parcels' => [
                    'returned' => $received->pluck('tracking_code')->values()->all(),
                    'kept' => $kept->pluck('tracking_code')->values()->all(),
                ]]);
            }

            $this->journal->record($request->user(), $courier->company_id, 'remittance',
                ($courier->user?->name ?? 'Livreur').' · attendu '.Money::format($remittance->amount_expected)
                .($remittance->difference !== 0 ? ' · écart '.($remittance->difference > 0 ? '+' : '').Money::format($remittance->difference) : ''),
                ['courier_id' => $courier->id, 'amount' => $remittance->amount_received, 'reference' => $remittance->reference()]);

            return $remittance;
        });

        // Reçu envoyé au livreur par WhatsApp
        $this->sendReceipt($remittance->load(['courier.user', 'receiver']));

        return response()->json(['data' => $this->remittanceData($remittance)], 201);
    }

    /** Reçu d'un versement : détail de ce qui a été réglé, colis rendus et gardés */
    public function showRemittance(Request $request, CourierRemittance $remittance): JsonResponse
    {
        $this->authorizeStaff($request);
        $remittance->load(['courier.user', 'receiver', 'canceller']);

        return response()->json(['data' => [
            ...$this->remittanceData($remittance),
            'company' => $request->user()->company?->name,
            'courier_phone' => $remittance->courier?->user?->phone,
            'collections' => CashCollection::where('remittance_id', $remittance->id)->with('order:id,tracking_code,recipient_name,merchant_id')
                ->orderBy('collected_at')->get()->map(fn ($c) => $this->collectionData($c)),
            'advances' => CourierAdvance::where('remittance_id', $remittance->id)->with('order:id,tracking_code')->orderBy('given_at')->get()
                ->map(fn (CourierAdvance $a) => $this->advanceData($a)),
            'expenses' => OrderExpense::where('remittance_id', $remittance->id)->with('order:id,tracking_code')->get()
                ->map(fn (OrderExpense $e) => ['id' => $e->id, 'tracking_code' => $e->order?->tracking_code, 'description' => $e->description(), 'amount' => $e->amount]),
            'can_cancel' => $remittance->cancelled_at === null && $remittance->received_at->isToday() && $request->user()->can(Permission::FinanceManage->value),
        ]]);
    }

    public function resendReceipt(Request $request, CourierRemittance $remittance): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);
        $sent = $this->sendReceipt($remittance->load(['courier.user', 'receiver']));
        abort_if($sent === null, 422, 'Ce livreur n\'a pas de numéro de téléphone.');

        return response()->json(['data' => ['sent' => true]]);
    }

    /** Annule un versement reçu par erreur, le jour même */
    public function cancelRemittance(Request $request, CourierRemittance $remittance): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'raison']);

        $this->cashDesk->cancel($request->user(), $remittance, $data['reason']);
        $this->journal->record($request->user(), $remittance->company_id, 'remittance_cancelled',
            ($remittance->courier?->user?->name ?? 'Livreur').' · '.$remittance->reference().' · « '.$data['reason'].' »',
            ['courier_id' => $remittance->courier_id, 'amount' => -$remittance->amount_received, 'reference' => $remittance->reference()]);

        return response()->json(['data' => $this->remittanceData($remittance->load(['courier.user', 'receiver', 'canceller']))]);
    }

    /** Journal de la caisse : qui a fait quoi sur l'argent, et quand */
    public function journal(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $data = $request->validate([
            'action' => ['nullable', 'string', 'max:30'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $logs = CashLog::query()->with('user:id,name')
            ->when($data['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($data['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->when($data['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d)->endOfDay()))
            ->latest('id')->limit(500)->get();

        return response()->json([
            'data' => $logs->map(fn (CashLog $l) => [
                'id' => $l->id,
                'action' => $l->action,
                'action_label' => CashLog::ACTIONS[$l->action] ?? $l->action,
                'description' => $l->description,
                'amount' => $l->amount,
                'reference' => $l->reference,
                'by' => $l->user?->name,
                'created_at' => $l->created_at,
            ]),
            'actions' => CashLog::ACTIONS,
            'users' => CashLog::query()->whereNotNull('user_id')->distinct()->pluck('user_id')
                ->pipe(fn ($ids) => User::whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])),
        ]);
    }

    private function sendReceipt(CourierRemittance $r): ?OutboundMessage
    {
        $phone = $r->courier?->user?->phone;
        if ($phone === null) {
            return null;
        }
        $gap = $r->difference === 0 ? 'Aucun écart.'
            : ($r->difference < 0 ? 'Écart de '.Money::format(-$r->difference).' retenu sur votre paie.' : 'Vous avez versé '.Money::format($r->difference).' de plus.');

        return app(Messenger::class)->whatsapp($r->company_id, $phone, 'courier', WhatsAppTemplate::CashReceipt, [
            $r->courier->user->name,
            Company::find($r->company_id)?->name ?? 'La caisse',
            $r->reference(),
            $r->received_at->format('d/m à H:i'),
            Money::format($r->amount_received),
            Money::format($r->amount_expected),
            $gap,
            count($r->parcels['returned'] ?? []),
            count($r->parcels['kept'] ?? []),
        ], ['event' => 'cash.receipt']);
    }

    public function remittances(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $request->validate(['courier_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $remittances = CourierRemittance::with(['courier.user', 'receiver'])
            ->when($request->filled('courier_id'), fn ($q) => $q->where('courier_id', $request->integer('courier_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('received_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('received_at', '<=', $request->date('to')))
            ->latest('received_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $remittances->map(fn ($r) => $this->remittanceData($r))]);
    }

    // ───────────── Marchands : grand livre et reversements ─────────────

    public function merchants(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $summaries = $this->payouts->summaries();
        $empty = ['available' => 0, 'pending_cash' => 0, 'unpaid_total' => 0];

        $merchants = Merchant::orderBy('business_name')->with('paymentMethods')->get()
            ->map(fn (Merchant $m) => [
                'merchant_id' => $m->id,
                'business_name' => $m->business_name,
                ...($summaries[$m->id] ?? $empty),
                // Payer avec : le moyen préféré, et combien restent à vérifier
                'payment_method' => ($preferred = $m->paymentMethods->firstWhere('is_preferred', true) ?? $m->paymentMethods->first())
                    ? ['summary' => $preferred->summary(), 'status' => $preferred->status] : null,
                'payment_methods_pending' => $m->paymentMethods->where('status', MerchantPaymentMethod::PENDING)->count(),
            ])
            ->filter(fn ($row) => $row['unpaid_total'] !== 0 || $request->boolean('all'))
            ->values();

        return response()->json(['data' => $merchants]);
    }

    public function ledger(Request $request, Merchant $merchant): JsonResponse
    {
        $this->authorizeMerchantAccess($request, $merchant);

        $request->validate(['unpaid' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'max:200']]);

        $entries = $merchant->ledgerEntries()
            ->with(['order:id,tracking_code,recipient_name,status', 'payout:id,reference,status'])
            ->when($request->boolean('unpaid'), fn ($q) => $q->whereNull('payout_id'))
            ->latest('id')
            ->paginate(min(max($request->integer('per_page', 50), 1), 500));

        // Solde après chaque ligne (les plus récentes d'abord) : total jusqu'à la première, puis on remonte
        $items = collect($entries->items());
        $balance = $items->isEmpty() || $request->boolean('unpaid') ? null
            : (int) $merchant->ledgerEntries()->where('id', '<=', $items->first()->id)->sum('amount');

        return response()->json([
            'data' => $items->map(function ($e) use (&$balance) {
                $row = $this->entryData($e) + ['balance' => $balance];
                if ($balance !== null) {
                    $balance -= $e->amount;
                }

                return $row;
            }),
            'meta' => ['current_page' => $entries->currentPage(), 'last_page' => $entries->lastPage(), 'total' => $entries->total()],
            'summary' => $this->payouts->summary($merchant),
        ]);
    }

    public function adjustMerchant(Request $request, Merchant $merchant): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0'],
            'description' => ['required', 'string', 'max:255'],
        ], [], ['amount' => 'montant', 'description' => 'motif']);

        $entry = $this->payouts->adjust($request->user(), $merchant, $data['amount'], $data['description']);
        $this->journal->record($request->user(), $merchant->company_id, 'merchant_adjustment', $merchant->business_name.' · « '.$data['description'].' »',
            ['merchant_id' => $merchant->id, 'amount' => $data['amount']]);

        return response()->json(['data' => $this->entryData($entry)], 201);
    }

    public function payouts(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can(Permission::FinanceView->value), 403);

        $request->validate(['merchant_id' => ['nullable', 'integer'], 'status' => ['nullable', 'string']]);

        $payouts = MerchantPayout::with('merchant')
            ->when($user->merchant_id !== null, fn ($q) => $q->where('merchant_id', $user->merchant_id))
            ->when($user->merchant_id === null && $request->filled('merchant_id'), fn ($q) => $q->where('merchant_id', $request->integer('merchant_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $payouts->map(fn ($p) => $this->payoutData($p))]);
    }

    public function storePayout(Request $request): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);

        $data = $request->validate([
            'merchant_id' => ['required', 'integer', Rule::exists('merchants', 'id')->where('company_id', $request->user()->company_id)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $payout = $this->payouts->create($request->user(), Merchant::findOrFail($data['merchant_id']), $data['notes'] ?? null);
        $this->journal->record($request->user(), $payout->company_id, 'payout_prepared', $payout->merchant->business_name.' · '.$payout->reference,
            ['merchant_id' => $payout->merchant_id, 'amount' => $payout->net_amount, 'reference' => $payout->reference]);

        return response()->json(['data' => $this->payoutData($payout->load('merchant'), withEntries: true)], 201);
    }

    public function showPayout(Request $request, MerchantPayout $payout): JsonResponse
    {
        $this->authorizeMerchantAccess($request, $payout->merchant);

        $data = $this->payoutData($payout->load('merchant'), withEntries: true);
        // Payer ce marchand : ses moyens de paiement, le préféré en premier
        $data['payment_methods'] = $payout->merchant->paymentMethods()->with('verifier:id,name')->get()
            ->map(fn ($m) => MerchantPaymentMethodController::data($m));

        return response()->json(['data' => $data]);
    }

    public function payPayout(Request $request, MerchantPayout $payout): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);
        $data = $this->validatePayment($request);

        $payout = $this->payouts->markPaid($request->user(), $payout, PaymentMethod::from($data['method']), $data['transaction_ref'] ?? null);
        $this->journal->record($request->user(), $payout->company_id, 'payout_paid',
            $payout->merchant->business_name.' · '.$payout->reference.' · '.$payout->method?->label().($payout->transaction_ref ? ', réf. '.$payout->transaction_ref : ''),
            ['merchant_id' => $payout->merchant_id, 'amount' => $payout->net_amount, 'reference' => $payout->reference]);

        return response()->json(['data' => $this->payoutData($payout->load('merchant'), withEntries: true)]);
    }

    public function cancelPayout(Request $request, MerchantPayout $payout): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);

        $payout = $this->payouts->cancel($payout)->load('merchant');
        $this->journal->record($request->user(), $payout->company_id, 'payout_cancelled', $payout->merchant->business_name.' · '.$payout->reference,
            ['merchant_id' => $payout->merchant_id, 'amount' => $payout->net_amount, 'reference' => $payout->reference]);

        return response()->json(['data' => $this->payoutData($payout)]);
    }

    // ───────────── Paie des livreurs ─────────────

    public function earnings(Request $request, Courier $courier): JsonResponse
    {
        $this->authorizeStaff($request);

        return response()->json([
            'data' => $courier->earnings()->with('order:id,tracking_code')->whereNull('payout_id')->latest('id')->get()
                ->map(fn ($e) => $this->earningData($e)),
            'summary' => $this->payroll->summary($courier),
        ]);
    }

    public function adjustCourier(Request $request, Courier $courier): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0'],
            'description' => ['required', 'string', 'max:255'],
        ], [], ['amount' => 'montant', 'description' => 'motif']);

        $earning = $this->payroll->adjust($request->user(), $courier, $data['amount'], $data['description']);
        $this->journal->record($request->user(), $courier->company_id, 'courier_adjustment', ($courier->user?->name ?? 'Livreur').' · « '.$data['description'].' »',
            ['courier_id' => $courier->id, 'amount' => $data['amount']]);

        return response()->json(['data' => $this->earningData($earning)], 201);
    }

    /**
     * Paiements des factures des clients (distribution, MAD) du mois : l'argent qui entre en caisse
     * en dehors des livreurs. Périmètre de l'équipe respecté (activités).
     */
    public function invoicePayments(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = CarbonImmutable::createFromFormat('Y-m-d', ($data['month'] ?? now()->format('Y-m')).'-01');

        $payments = ClientInvoicePayment::query()
            ->whereHas('invoice')
            ->with(['invoice.merchant:id,business_name', 'recorder:id,name'])
            ->whereBetween('paid_on', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->orderByDesc('paid_on')->orderByDesc('id')->get();

        return response()->json([
            'data' => $payments->map(fn (ClientInvoicePayment $p) => [
                'id' => $p->id,
                'paid_on' => $p->paid_on->toDateString(),
                'amount' => $p->amount,
                'method' => $p->method->value,
                'method_label' => $p->method->label(),
                'reference' => $p->reference,
                'recorded_by' => $p->recorder?->name,
                'invoice' => [
                    'id' => $p->invoice->id,
                    'number' => $p->invoice->number,
                    'activity' => $p->invoice->activity?->value,
                    'activity_label' => $p->invoice->activity?->label(),
                    'merchant' => $p->invoice->merchant?->business_name,
                ],
            ])->values(),
            'total' => $payments->sum('amount'),
            'by_method' => $payments->groupBy(fn ($p) => $p->method->label())->map->sum('amount'),
        ]);
    }

    public function courierPayouts(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $payouts = CourierPayout::with('courier.user')
            ->when($request->filled('courier_id'), fn ($q) => $q->where('courier_id', $request->integer('courier_id')))
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $payouts->map(fn ($p) => $this->courierPayoutData($p))]);
    }

    public function storeCourierPayout(Request $request): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);

        $data = $request->validate([
            'courier_id' => ['required', 'integer', Rule::exists('couriers', 'id')->where('company_id', $request->user()->company_id)],
        ]);

        $payout = $this->payroll->create($request->user(), Courier::findOrFail($data['courier_id']));

        return response()->json(['data' => $this->courierPayoutData($payout->load('courier.user'), withEarnings: true)], 201);
    }

    public function showCourierPayout(Request $request, CourierPayout $courierPayout): JsonResponse
    {
        $this->authorizeStaff($request);

        return response()->json(['data' => $this->courierPayoutData($courierPayout->load('courier.user'), withEarnings: true)]);
    }

    public function payCourierPayout(Request $request, CourierPayout $courierPayout): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);
        $data = $request->validate([
            // « compensation » : le livreur garde sa paie sur l'argent encaissé
            'method' => ['required', Rule::in([...array_column(PaymentMethod::cases(), 'value'), CourierPayroll::COMPENSATION])],
            'transaction_ref' => ['nullable', 'string', 'max:100'],
        ], [], ['method' => 'mode de paiement', 'transaction_ref' => 'référence']);

        $method = $data['method'] === CourierPayroll::COMPENSATION ? CourierPayroll::COMPENSATION : PaymentMethod::from($data['method']);
        $payout = $this->payroll->markPaid($request->user(), $courierPayout, $method, $data['transaction_ref'] ?? null);
        $payout->loadMissing('courier.user');
        $this->journal->record($request->user(), $payout->company_id, 'payslip_paid',
            ($payout->courier?->user?->name ?? 'Livreur').' · '.$payout->reference.' · '.($payout->compensated ? 'gardé sur l\'encaissé' : $payout->method?->label()),
            ['courier_id' => $payout->courier_id, 'amount' => $payout->amount, 'reference' => $payout->reference]);

        return response()->json(['data' => $this->courierPayoutData($payout->load('courier.user'), withEarnings: true)]);
    }

    public function cancelCourierPayout(Request $request, CourierPayout $courierPayout): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);

        $payout = $this->payroll->cancel($courierPayout)->load('courier.user');
        $this->journal->record($request->user(), $payout->company_id, 'payslip_cancelled', ($payout->courier?->user?->name ?? 'Livreur').' · '.$payout->reference,
            ['courier_id' => $payout->courier_id, 'amount' => $payout->amount, 'reference' => $payout->reference]);

        return response()->json(['data' => $this->courierPayoutData($payout)]);
    }

    // ───────────── Livreur connecté ─────────────

    public function wallet(Request $request): JsonResponse
    {
        $courier = $request->user()->courier;
        abort_if($courier === null, 403, 'Profil livreur introuvable.');

        return response()->json(['data' => [
            ...$this->payroll->summary($courier),
            'earned_today' => (int) $courier->earnings()->whereIn('type', ['pickup', 'delivery', 'failed_attempt', 'return'])->whereDate('created_at', today())->sum('amount'),
            ...$this->courierExtras($courier),
            'collections' => $courier->collections()->inCourierHands()->with('order:id,tracking_code,recipient_name,merchant_id')
                ->orderBy('collected_at')->get()->map(fn ($c) => $this->collectionData($c)),
            // Fiches de paie préparées, pas encore payées
            'pending_payslips' => $courier->payouts()->where('status', 'draft')->orderBy('id')->get()
                ->map(fn ($p) => ['reference' => $p->reference, 'amount' => $p->amount, 'period_start' => $p->period_start?->toDateString(), 'period_end' => $p->period_end?->toDateString()]),
            // Sa paie : salaire de base et prochaine fiche selon la période du plan
            'pay' => $this->courierPayInfo($courier),
            // Détail des gains à recevoir (montant, calcul selon le plan)
            'earnings' => $courier->earnings()->with('order:id,tracking_code')->whereNull('payout_id')->latest('id')->limit(50)->get()
                ->map(fn ($e) => $this->earningData($e)),
            'recent_payouts' => $courier->payouts()->where('status', 'paid')->latest('paid_at')->limit(5)->get()
                ->map(fn ($p) => ['reference' => $p->reference, 'amount' => $p->amount, 'paid_at' => $p->paid_at]),
        ]]);
    }

    /**
     * @return array{base_salary: int, period_label: ?string, next_payslip: ?string, objectives: list<array<string, mixed>>}|null
     */
    private function courierPayInfo(Courier $courier): ?array
    {
        $plan = app(CourierPay::class)->planFor($courier);
        if ($plan === null || ($plan->base_salary === 0 && $plan->pay_period === null)) {
            return null;
        }

        $period = $plan->periodContaining(today());
        $plan->loadMissing('bonuses');
        $bonuses = app(PayBonuses::class);

        return [
            'base_salary' => $plan->base_salary,
            'period_label' => $plan->periodLabel(),
            // Fiche préparée le lendemain de la fin de période
            'next_payslip' => $period ? $period[1]->addDay()->toDateString() : null,
            // Primes d'objectifs : où il en est sur la période en cours
            'objectives' => $period && $plan->bonuses->isNotEmpty()
                ? $bonuses->progress($plan, $bonuses->measure($courier, $period[0], $period[1]))
                : [],
        ];
    }

    // ───────────── Outils ─────────────

    private function authorizeStaff(Request $request, bool $manage = false): void
    {
        $user = $request->user();
        $permission = $manage ? Permission::FinanceManage : Permission::FinanceView;

        abort_unless($user->merchant_id === null && $user->can($permission->value), 403);
    }

    private function authorizeMerchantAccess(Request $request, Merchant $merchant): void
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless(
            $user->can(Permission::FinanceView->value) && ($user->merchant_id === null || $user->merchant_id === $merchant->id),
            403,
        );
    }

    private function validatePayment(Request $request): array
    {
        return $request->validate([
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'transaction_ref' => ['nullable', 'string', 'max:100'],
        ], [], ['method' => 'mode de paiement', 'transaction_ref' => 'référence']);
    }

    private function collectionData(CashCollection $c): array
    {
        return [
            'id' => $c->id,
            'order_id' => $c->order_id,
            'tracking_code' => $c->order?->tracking_code,
            'recipient_name' => $c->order?->recipient_name,
            'amount_expected' => $c->amount_expected,
            'amount_collected' => $c->amount_collected,
            'method' => $c->method,
            'method_label' => $c->method->label(),
            'received_by_company' => $c->received_by_company,
            'collected_at' => $c->collected_at,
            'remittance_id' => $c->remittance_id,
        ];
    }

    /**
     * Avances et frais à régler avec la caisse lors du prochain versement.
     *
     * @return array{balance: array<string, int>, advances: mixed, expenses: mixed}
     */
    private function courierExtras(Courier $courier): array
    {
        return [
            'balance' => $this->courierCash->balance($courier),
            'parcels' => $this->custody->held($courier)->map(fn ($o) => ParcelCustody::parcelData($o))->values(),
            'advances' => $courier->advances()->unsettled()->with('order:id,tracking_code')->orderBy('given_at')->get()
                ->map(fn (CourierAdvance $a) => $this->advanceData($a)),
            'expenses' => $courier->expenses()->owedToCourier()->with('order:id,tracking_code,recipient_name')->orderBy('created_at')->get()
                ->map(fn (OrderExpense $e) => [
                    'id' => $e->id,
                    'order_id' => $e->order_id,
                    'tracking_code' => $e->order?->tracking_code,
                    'recipient_name' => $e->order?->recipient_name,
                    'type' => $e->type,
                    'description' => $e->description(),
                    'amount' => $e->amount,
                    'created_at' => $e->created_at,
                ]),
        ];
    }

    private function advanceData(CourierAdvance $a): array
    {
        return [
            'id' => $a->id,
            'amount' => $a->amount,
            'reason' => $a->reason,
            // Paie gardée sur l'encaissé (montant négatif) plutôt qu'avance de la caisse
            'pay_kept' => $a->courier_payout_id !== null,
            'order_id' => $a->order_id,
            'tracking_code' => $a->order?->tracking_code,
            'given_at' => $a->given_at,
            'cancelled_at' => $a->cancelled_at,
            // Avance de la caisse donnée aujourd'hui, pas encore réglée : annulable
            'can_cancel' => $a->cancelled_at === null && $a->remittance_id === null && $a->courier_payout_id === null && $a->given_at?->isToday(),
        ];
    }

    private function remittanceData(CourierRemittance $r): array
    {
        return [
            'id' => $r->id,
            'courier_id' => $r->courier_id,
            'courier_name' => $r->courier?->user?->name,
            'amount_expected' => $r->amount_expected,
            'amount_received' => $r->amount_received,
            'difference' => $r->difference,
            'received_by' => $r->receiver?->name,
            'received_at' => $r->received_at,
            'notes' => $r->notes,
            'parcels_returned' => $r->parcels['returned'] ?? [],
            'parcels_kept' => $r->parcels['kept'] ?? [],
            'reference' => $r->reference(),
            'cancelled_at' => $r->cancelled_at,
            'cancel_reason' => $r->cancel_reason,
            'cancelled_by' => $r->relationLoaded('canceller') ? $r->canceller?->name : null,
        ];
    }

    private function entryData(MerchantLedgerEntry $e): array
    {
        return [
            'id' => $e->id,
            'type' => $e->type,
            'type_label' => $e->type->label(),
            'amount' => $e->amount,
            'description' => $e->description,
            'order' => $e->order ? ['id' => $e->order->id, 'tracking_code' => $e->order->tracking_code, 'recipient_name' => $e->order->recipient_name] : null,
            'payout' => $e->payout ? ['id' => $e->payout->id, 'reference' => $e->payout->reference, 'status' => $e->payout->status] : null,
            'created_at' => $e->created_at,
        ];
    }

    private function payoutData(MerchantPayout $p, bool $withEntries = false): array
    {
        return [
            'id' => $p->id,
            'reference' => $p->reference,
            'merchant' => ['id' => $p->merchant->id, 'business_name' => $p->merchant->business_name, 'phone' => $p->merchant->phone],
            'period_start' => $p->period_start?->toDateString(),
            'period_end' => $p->period_end?->toDateString(),
            'total_collected' => $p->total_collected,
            'total_fees' => $p->total_fees,
            'total_shipping_fees' => $p->total_shipping_fees,
            'total_other_fees' => $p->total_other_fees,
            'total_storage_fees' => $p->total_storage_fees,
            'total_adjustments' => $p->total_adjustments,
            'net_amount' => $p->net_amount,
            'status' => $p->status,
            'status_label' => $p->status->label(),
            'method' => $p->method,
            'method_label' => $p->method?->label(),
            'transaction_ref' => $p->transaction_ref,
            'notes' => $p->notes,
            'paid_at' => $p->paid_at,
            'created_at' => $p->created_at,
        ] + ($withEntries ? ['entries' => $p->entries()
            ->with(['order:id,tracking_code,recipient_name,status', 'payout:id,reference,status'])
            ->where('type', '!=', 'payout')->orderBy('id')->get()->map(fn ($e) => $this->entryData($e))] : []);
    }

    private function earningData(CourierEarning $e): array
    {
        return [
            'id' => $e->id,
            'type' => $e->type,
            'type_label' => $e->type->label(),
            'amount' => $e->amount,
            'description' => $e->description,
            // Calcul du gain selon le plan (ex. « 40 % de 2 500 F »)
            'detail' => $e->detail,
            'tracking_code' => $e->order?->tracking_code,
            'created_at' => $e->created_at,
        ];
    }

    private function courierPayoutData(CourierPayout $p, bool $withEarnings = false): array
    {
        return [
            'id' => $p->id,
            'reference' => $p->reference,
            'courier_id' => $p->courier_id,
            'courier_name' => $p->courier?->user?->name,
            'period_start' => $p->period_start?->toDateString(),
            'period_end' => $p->period_end?->toDateString(),
            'amount' => $p->amount,
            'status' => $p->status,
            'status_label' => $p->status->label(),
            'automatic' => $p->automatic,
            'compensated' => $p->compensated,
            'method_label' => $p->compensated ? 'gardé sur l\'encaissé' : $p->method?->label(),
            'transaction_ref' => $p->transaction_ref,
            'paid_at' => $p->paid_at,
            'created_at' => $p->created_at,
        ] + ($withEarnings ? ['earnings' => $p->earnings()->with('order:id,tracking_code')->orderBy('id')->get()
            ->map(fn ($e) => $this->earningData($e))] : []);
    }
}
