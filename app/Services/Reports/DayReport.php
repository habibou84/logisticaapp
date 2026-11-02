<?php

namespace App\Services\Reports;

use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use App\Models\BillingPlan;
use App\Models\CashCollection;
use App\Models\Company;
use App\Models\Courier;
use App\Models\MerchantLedgerEntry;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Services\Assignment\MadCouriers;
use App\Services\Couriers\DailyActivity;
use App\Services\Finance\ClientSpace;
use App\Services\Orders\ActivityOptions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Point du jour (ou d'une période) de la page Courses et du tableau de bord, repris de Jibiat et complété par activité :
 * - toutes : courses prévues et livrées, en cours, échecs, reports, retours, annulations, nouvelles courses ;
 * - e-commerce : recette de livraison, encaissé chez les clients (caisse ou livreurs), à reverser (droit caisse) ;
 * - distribution : plis et chèques comptés un à un, accusés de réception manquants, délai interne dépassé, lots ;
 * - MAD : livreurs mis à disposition aujourd'hui et courses faites pour leurs clients ;
 * - livreurs de la période.
 *
 * Les courses prévues sont celles dont le jour de livraison tombe dans la période ; les livrées sont celles livrées
 * pendant la période (quel que soit leur jour prévu). Les courses suivent le périmètre de l'utilisateur (activités).
 */
class DayReport
{
    /** Livreurs listés au plus dans « Livreurs du jour » */
    public const COURIERS = 8;

    private const IN_PROGRESS = [
        OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::PickupAssigned, OrderStatus::PickupInProgress,
        OrderStatus::PickedUp, OrderStatus::AtHub, OrderStatus::DeliveryAssigned, OrderStatus::OutForDelivery,
    ];

    public function __construct(
        private readonly MadCouriers $mad,
        private readonly ActivityOptions $options,
        private readonly DashboardSettings $settings,
    ) {}

    /**
     * @param  list<string>  $activities  activités vues par l'utilisateur (ou celle choisie)
     * @return array<string, mixed>
     */
    public function build(Company $company, string $from, bool $withMoney, ?string $to = null, array $activities = ['ecommerce', 'distribution', 'mad']): array
    {
        $to ??= $from;
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');
        $start = CarbonImmutable::parse($from, $tz)->startOfDay();
        $end = CarbonImmutable::parse($to, $tz)->endOfDay();
        $today = CarbonImmutable::now($tz)->toDateString();
        $orders = fn (): Builder => Order::query()->where('orders.company_id', $company->id)->whereIn('orders.activity', $activities);
        $columns = ['id', 'merchant_id', 'activity', 'shipment_type', 'shipment_quantity', 'status', 'billing_plan_id', 'internal_due_at', 'delivery_courier_id'];

        $planned = $orders()->forPeriod($from, $to, 'delivery')->get($columns);
        $count = fn (array $statuses) => $planned->whereIn('status', $statuses)->count();
        $delivered = $orders()->where('status', OrderStatus::Delivered)
            ->whereBetween('delivered_at', [$start->utc(), $end->utc()])->get([...$columns, 'is_shipping']);
        $created = fn (): Builder => $orders()->whereBetween('created_at', [$start->utc(), $end->utc()]);

        $report = [
            'day' => $start->toDateString(),
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'activities' => array_values($activities),
            'counts' => [
                'planned' => $planned->count(),
                'delivered' => $delivered->count(),
                'delivered_planned' => $planned->where('status', OrderStatus::Delivered)->count(),
                'in_progress' => $count(self::IN_PROGRESS),
                'failed' => $count([OrderStatus::DeliveryFailed]),
                'rescheduled' => $count([OrderStatus::Rescheduled]),
                'returned' => $count([OrderStatus::ReturnAssigned, OrderStatus::Returning, OrderStatus::Returned]),
                'cancelled' => $count([OrderStatus::Cancelled, OrderStatus::Rejected]),
                'created' => $created()->count(),
                'created_later' => $created()->whereRaw(Order::DELIVERY_DAY_SQL.' > ?', [$end->toDateString()])->count(),
            ],
            'money' => null,
            'distribution' => in_array('distribution', $activities, true) ? $this->distribution($company, $planned, $delivered, $today) : null,
            'mad' => in_array('mad', $activities, true) && $start->toDateString() <= $today && $end->toDateString() >= $today
                ? $this->madToday($company, $orders, $start, $end) : null,
            'couriers' => $this->couriers($company, $delivered, $withMoney),
            // Point envoyé sur WhatsApp : avec les montants (si l'entreprise le veut et si l'utilisateur voit la caisse)
            'share_amounts' => $withMoney && $this->settings->for($company)['share_amounts'],
        ];
        if (! $withMoney || ! in_array('ecommerce', $activities, true)) {
            return $report;
        }

        // Argent : e-commerce seulement (la distribution et la MAD sont facturées au mois)
        $ecommerce = $delivered->filter(fn (Order $o) => DailyActivity::activityOf($o) === 'ecommerce');
        $ids = $ecommerce->pluck('id');
        $ledger = MerchantLedgerEntry::withoutGlobalScopes()->whereIn('order_id', $ids)
            ->whereIn('type', [LedgerEntryType::DeliveryFee->value, LedgerEntryType::ReturnFee->value, LedgerEntryType::ShippingFee->value, LedgerEntryType::OtherFee->value])
            ->groupBy('type')->selectRaw('type, SUM(amount) AS total')->pluck('total', 'type');
        $fee = fn (LedgerEntryType $type) => -(int) ($ledger[$type->value] ?? 0);
        $fees = $fee(LedgerEntryType::DeliveryFee) + $fee(LedgerEntryType::ReturnFee);

        $collections = fn (): Builder => CashCollection::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('order_id', $ids);
        $collected = (int) $collections()->sum('amount_collected');
        $withCouriers = (int) $collections()->inCourierHands()->sum('amount_collected');

        $report['money'] = [
            'delivered' => $ecommerce->count(),
            'fees' => $fees,
            'shipping_fees' => $fee(LedgerEntryType::ShippingFee),
            'other_fees' => $fee(LedgerEntryType::OtherFee),
            'collected' => $collected,
            'at_desk' => $collected - $withCouriers,
            'with_couriers' => $withCouriers,
            'couriers_holding' => $collections()->inCourierHands()->where('amount_collected', '>', 0)->distinct()->count('courier_id'),
            'net_to_merchants' => $collected - $fees - $fee(LedgerEntryType::ShippingFee) - $fee(LedgerEntryType::OtherFee),
            'merchants' => $ecommerce->pluck('merchant_id')->unique()->count(),
        ];

        return $report;
    }

    /**
     * Distribution : plis et chèques comptés un à un, accusés manquants sur les livrées, délai interne dépassé sur les
     * prévues encore en cours, lots en cours dont l'échéance approche (réglage « alerte des lots »).
     *
     * @param  Collection<int, Order>  $planned
     * @param  Collection<int, Order>  $delivered
     * @return array<string, mixed>
     */
    private function distribution(Company $company, Collection $planned, Collection $delivered, string $today): array
    {
        $only = fn (Collection $list) => $list->filter(fn (Order $o) => DailyActivity::activityOf($o) === 'distribution');
        $plannedD = $only($planned);
        $deliveredD = $only($delivered);
        $units = fn (Collection $list) => (int) $list->sum(fn (Order $o) => DailyActivity::units($o));

        $withPhoto = $deliveredD->isEmpty() ? collect() : OrderAttachment::query()->whereIn('order_id', $deliveredD->pluck('id'))
            ->where('type', 'photo_delivery')->pluck('order_id')->flip();
        $missing = $deliveredD->filter(fn (Order $o) => ! isset($withPhoto[$o->id]) && ($this->options->proof($o)['proof_photo'] ?? false))->count();

        return [
            'planned' => $plannedD->count(),
            'delivered' => $deliveredD->count(),
            'units_planned' => $units($plannedD),
            'units_delivered' => $units($deliveredD),
            'units_delivered_planned' => $units($plannedD->where('status', OrderStatus::Delivered)),
            'missing_receipts' => $missing,
            'internal_late' => $plannedD->filter(fn (Order $o) => ! $o->status->isFinal() && $o->internal_due_at?->isPast())->count(),
            'lots_due' => $this->lotsDue($company, $today),
        ];
    }

    /**
     * Lots de distribution en cours, pas terminés, dont l'échéance arrive dans le délai d'alerte (ou est passée).
     *
     * @return list<array<string, mixed>>
     */
    public function lotsDue(Company $company, ?string $today = null): array
    {
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');
        $hours = $this->settings->for($company)['lot_due_hours'];
        $limit = CarbonImmutable::now($tz)->addHours($hours)->toDateString();
        $today ??= CarbonImmutable::now($tz)->toDateString();
        $space = app(ClientSpace::class);

        return BillingPlan::forCompany($company->id)->lots()->activeOn($today)->whereNotNull('lot_due_on')
            ->whereDate('lot_due_on', '<=', $limit)->with('merchant:id,business_name')->orderBy('lot_due_on')->limit(20)->get()
            ->map(fn (BillingPlan $lot) => [
                'id' => $lot->id,
                'name' => $lot->lotName(),
                'merchant' => $lot->merchant?->business_name,
                'due_on' => $lot->lot_due_on->toDateString(),
                ...collect($space->lotProgress($lot))->only(['included', 'used', 'delivered', 'in_progress', 'late', 'done'])->all(),
            ])->reject(fn (array $l) => $l['done'])->values()->all();
    }

    /**
     * MAD aujourd'hui : livreurs mis à disposition, clients servis et courses livrées pour eux sur la période.
     *
     * @param  callable(): Builder  $orders
     * @return array<string, mixed>
     */
    private function madToday(Company $company, callable $orders, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $reservations = collect($this->mad->reservations($company->id));
        $reserved = $reservations->filter(fn (array $r) => in_array(CarbonImmutable::now($company->timezone ?: config('app.timezone'))->dayOfWeekIso, $r['days'], true));

        return [
            'couriers' => $reserved->count(),
            'clients' => $reserved->pluck('merchant_id')->unique()->count(),
            'delivered' => $orders()->where('activity', 'mad')->where('status', OrderStatus::Delivered)
                ->whereBetween('delivered_at', [$start->utc(), $end->utc()])->count(),
            'in_progress' => $orders()->where('activity', 'mad')->whereIn('status', OrderStatus::values(self::IN_PROGRESS))->count(),
            'uncovered' => $this->madUncovered($company),
        ];
    }

    /**
     * Contrats MAD en cours sans livreur désigné : le client attend un livreur que personne ne lui a réservé.
     *
     * @return list<array{id: int, merchant: ?string}>
     */
    public function madUncovered(Company $company): array
    {
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');

        return BillingPlan::forCompany($company->id)->where('formula', 'mad_contract')->activeOn(CarbonImmutable::now($tz)->toDateString())
            ->whereDoesntHave('couriers')->with('merchant:id,business_name')->orderBy('id')->limit(20)->get()
            ->map(fn (BillingPlan $p) => ['id' => $p->id, 'merchant' => $p->merchant?->business_name])->all();
    }

    /**
     * Livreurs de la période : courses livrées, colis en main maintenant, argent encaissé (si droit caisse) et client
     * MAD du jour, du plus actif au moins actif.
     *
     * @param  Collection<int, Order>  $delivered
     * @return list<array<string, mixed>>
     */
    private function couriers(Company $company, Collection $delivered, bool $withMoney): array
    {
        $byCourier = $delivered->whereNotNull('delivery_courier_id')->groupBy('delivery_courier_id');
        $inHand = Order::withoutGlobalScopes()->where('company_id', $company->id)->whereNotNull('held_by_courier_id')
            ->whereNotIn('status', Order::finalStatuses())
            ->groupBy('held_by_courier_id')->selectRaw('held_by_courier_id, COUNT(*) AS n')->pluck('n', 'held_by_courier_id');
        $ids = $byCourier->keys()->merge($inHand->keys())->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $collected = $withMoney
            ? CashCollection::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('order_id', $delivered->pluck('id'))
                ->groupBy('courier_id')->selectRaw('courier_id, SUM(amount_collected) AS total')->pluck('total', 'courier_id')
            : collect();
        $names = Courier::withoutGlobalScopes()->whereIn('id', $ids)->with('user:id,name')->get()->mapWithKeys(fn (Courier $c) => [$c->id => $c->user?->name]);
        $mad = $this->mad->reservations($company->id);

        return $ids->map(fn ($id) => [
            'id' => (int) $id,
            'name' => $names[$id] ?? null,
            'delivered' => isset($byCourier[$id]) ? $byCourier[$id]->count() : 0,
            'units' => isset($byCourier[$id]) ? (int) $byCourier[$id]->sum(fn (Order $o) => DailyActivity::units($o)) : 0,
            'in_hand' => (int) ($inHand[$id] ?? 0),
            'collected' => $withMoney ? (int) ($collected[$id] ?? 0) : null,
            'mad_client' => $mad[$id]['merchant'] ?? null,
        ])->sortByDesc(fn ($c) => [$c['delivered'], $c['in_hand']])->take(self::COURIERS)->values()->all();
    }
}
