<?php

namespace App\Services\Reports;

use App\Enums\Activity;
use App\Enums\BillingFormula;
use App\Enums\ClientInvoiceStatus;
use App\Enums\CompanyStatus;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\WhatsAppTemplate;
use App\Mail\StatsAlertsMail;
use App\Models\BillingPlan;
use App\Models\ClientInvoice;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierRemittance;
use App\Models\DailySnapshot;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use App\Models\Zone;
use App\Notifications\StatsAlertRaised;
use App\Services\Messaging\Messenger;
use App\Services\Orders\AwaitingCourier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Alertes statistiques du matin (réglages : Statistiques › Analyse et partage › Partage et alertes), aux administrateurs :
 * - taux de réussite d'une zone sous le seuil sur les 7 derniers jours (assez de passages) ;
 * - échecs d'un livreur doublés par rapport aux 7 jours d'avant ;
 * - client en baisse : courses des 30 derniers jours sous la moyenne mensuelle des 3 mois d'avant.
 * Une même alerte n'est pas renvoyée avant 7 jours. Et le relevé du soir (courses non traitées…).
 */
class StatsAlerts
{
    public function __construct(private readonly StatsSettings $settings, private readonly Messenger $messenger) {}

    /** Toutes les entreprises actives : alertes envoyées */
    public function sendAll(?CarbonImmutable $now = null): int
    {
        return Company::query()->where('status', CompanyStatus::Active->value)->get()
            ->sum(fn (Company $c) => count($this->send($c, $now)));
    }

    /**
     * @return list<array{event: string, key: string, title: string, body: string, href: string}>
     */
    public function send(Company $company, ?CarbonImmutable $now = null): array
    {
        $alerts = collect($this->detect($company, $now))->reject(fn (array $a) => Cache::has($this->cacheKey($company, $a['key'])))->values();
        if ($alerts->isEmpty()) {
            return [];
        }
        // Destinataires : les administrateurs et les comptes qui ont l'autorisation de direction
        $people = User::query()->where('company_id', $company->id)->whereNull('merchant_id')->where('status', 'active')->get()
            ->filter(fn (User $u) => $u->primaryRole() === Role::Admin || $u->can(Permission::DirectionView->value))->values();
        $events = $this->settings->alertEvents($company);
        $byEmail = [];
        foreach ($alerts as $a) {
            $channels = $events[$a['event']];
            Cache::put($this->cacheKey($company, $a['key']), true, now()->addDays(7));
            if ($channels['bell']) {
                Notification::send($people, new StatsAlertRaised($a['key'], $a['title'], $a['body'], $a['href']));
            }
            if ($channels['whatsapp']) {
                foreach ($people as $person) {
                    $this->messenger->whatsapp($company->id, $person->phone, 'staff', WhatsAppTemplate::StatsAlert,
                        [strtok($person->name, ' ') ?: $person->name, $company->name, $a['body'], $company->url($a['href'])], ['event' => 'stats.alert']);
                }
            }
            if ($channels['email']) {
                $byEmail[] = $a;
            }
        }
        // E-mail : un seul message pour toutes les alertes du jour
        $recipients = $this->settings->alertRecipients($company);
        if ($byEmail !== [] && $recipients !== []) {
            Mail::to($recipients)->send(new StatsAlertsMail($company, $byEmail));
        }

        return $alerts->all();
    }

    /**
     * @return list<array{event: string, key: string, title: string, body: string, href: string}>
     */
    public function detect(Company $company, ?CarbonImmutable $now = null): array
    {
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');
        $now ??= CarbonImmutable::now($tz);
        $cfg = $this->settings->alertEvents($company);
        $min = (int) $this->settings->for($company)['alerts']['min_volume'];
        $weekStart = $now->startOfDay()->subDays(7);
        $prevStart = $weekStart->subDays(7);
        $out = [];

        $failures = fn (CarbonImmutable $from, CarbonImmutable $to) => OrderEvent::query()
            ->whereIn('order_events.order_id', Order::withoutGlobalScopes()->where('company_id', $company->id)->select('id'))
            ->where('type', OrderEventType::Incident->value)->where('from_status', OrderStatus::OutForDelivery->value)
            ->whereIn('to_status', [OrderStatus::DeliveryFailed->value, OrderStatus::Rescheduled->value])
            ->whereBetween('order_events.created_at', [$from->utc(), $to->utc()])
            ->with(['order' => fn ($q) => $q->withoutGlobalScopes()->select(['id', 'delivery_zone_id', 'delivery_courier_id']), 'assignment:id,courier_id'])->get(['id', 'order_id', 'assignment_id']);
        $delivered = fn (CarbonImmutable $from, CarbonImmutable $to) => Order::withoutGlobalScopes()->where('company_id', $company->id)
            ->where('status', OrderStatus::Delivered->value)->whereBetween('delivered_at', [$from->utc(), $to->utc()])->get(['delivery_zone_id', 'delivery_courier_id']);
        $week = $failures($weekStart, $now);
        $weekDelivered = $delivered($weekStart, $now);

        // Zones sous le seuil de réussite
        if ($cfg['zone_success']['active']) {
            $floor = $cfg['zone_success']['threshold'];
            $ok = $weekDelivered->groupBy('delivery_zone_id')->map->count();
            $ko = $week->groupBy(fn ($e) => $e->order?->delivery_zone_id)->map->count();
            $names = Zone::withoutGlobalScopes()->whereIn('id', $ok->keys()->merge($ko->keys())->filter())->pluck('name', 'id');
            foreach ($ok->keys()->merge($ko->keys())->unique()->filter() as $zone) {
                $total = ($ok[$zone] ?? 0) + ($ko[$zone] ?? 0);
                $rate = $total ? round(($ok[$zone] ?? 0) / $total * 100) : 100;
                if ($total >= $min && $rate < $floor) {
                    $out[] = $this->alert('zone_success', "zone:{$zone}", "Réussite en baisse : {$names[$zone]}",
                        "taux de réussite de {$names[$zone]} à {$rate} % sur 7 jours (seuil {$floor} %, {$total} passages)", '/admin/statistiques?vue=ecommerce&detail=zones');
                }
            }
        }

        // Livreurs dont les échecs doublent
        if ($cfg['courier_failures']['active']) {
            $courierOf = fn ($e) => $e->assignment?->courier_id ?? $e->order?->delivery_courier_id;
            $now7 = $week->groupBy($courierOf)->map->count();
            $before = $failures($prevStart, $weekStart)->groupBy($courierOf)->map->count();
            $names = Courier::withoutGlobalScopes()->whereIn('id', $now7->keys()->filter())->with('user:id,name')->get()->mapWithKeys(fn ($c) => [$c->id => $c->user?->name]);
            foreach ($now7 as $courier => $count) {
                $prev = (int) ($before[$courier] ?? 0);
                if ($courier && $count >= max(3, intdiv($min, 2)) && $count >= 2 * max(1, $prev)) {
                    $name = $names[$courier] ?? 'Un livreur';
                    $out[] = $this->alert('courier_failures', "courier:{$courier}", "Échecs en hausse : {$name}",
                        "{$name} a {$count} échecs sur 7 jours contre {$prev} la semaine d'avant", '/admin/statistiques?vue=teams');
                }
            }
        }

        // Clients en baisse
        if ($cfg['client_drop']['active']) {
            $orders = fn (CarbonImmutable $from, CarbonImmutable $to) => Order::withoutGlobalScopes()->where('company_id', $company->id)
                ->whereBetween('created_at', [$from->utc(), $to->utc()])->selectRaw('merchant_id, COUNT(*) AS n')->groupBy('merchant_id')->pluck('n', 'merchant_id');
            $last = $orders($now->subDays(30), $now);
            $before = $orders($now->subDays(120), $now->subDays(30));
            $names = Merchant::withoutGlobalScopes()->whereIn('id', $before->keys())->pluck('business_name', 'id');
            foreach ($before as $merchant => $n) {
                $avg = $n / 3;
                $recent = (int) ($last[$merchant] ?? 0);
                $drop = $avg > 0 ? (int) round((1 - $recent / $avg) * 100) : 0;
                if ($avg >= $min && $drop >= $cfg['client_drop']['threshold']) {
                    $out[] = $this->alert('client_drop', "client:{$merchant}", "Client en baisse : {$names[$merchant]}",
                        "{$names[$merchant]} a envoyé {$recent} courses en 30 jours contre ".(int) round($avg)." par mois avant (−{$drop} %)", '/admin/statistiques?vue=ecommerce&detail=loyalty');
                }
            }
        }

        return [...$out, ...$this->business($company, $now, $cfg)];
    }

    /**
     * Relevé du soir : courses non traitées, délai interne dépassé, sans livreur, livrées et échecs du jour.
     */
    public function snapshot(Company $company, ?CarbonImmutable $now = null): DailySnapshot
    {
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');
        $now ??= CarbonImmutable::now($tz);
        $orders = fn () => Order::withoutGlobalScopes()->where('company_id', $company->id)->whereNull('deleted_at');
        $day = [$now->startOfDay()->utc(), $now->endOfDay()->utc()];

        $count = fn (callable $orders) => [
            'untreated' => $orders()->untreated($company, $now->toDateString())->count(),
            'internal_late' => $orders()->whereNotNull('internal_due_at')->where('internal_due_at', '<=', $now->utc())->whereNotIn('status', Order::finalStatuses())->count(),
            'unassigned' => AwaitingCourier::scope($orders(), null, $now->toDateString())->count(),
            'delivered' => $orders()->where('status', OrderStatus::Delivered->value)->whereBetween('delivered_at', $day)->count(),
            'created' => $orders()->whereBetween('created_at', $day)->count(),
        ];

        return DailySnapshot::withoutGlobalScopes()->updateOrCreate(['company_id' => $company->id, 'day' => $now->toDateString()], ['data' => [
            ...$count($orders),
            // Le même relevé pour chaque activité (statistiques d'une seule activité)
            'by_activity' => collect(Activity::cases())->mapWithKeys(fn (Activity $a) => [$a->value => $count(fn () => $orders()->where('activity', $a->value))])->all(),
        ]]);
    }

    /**
     * Alertes de gestion : forfait dépassé, facture en retard, contrat à échéance, objectif en retard, écart de
     * caisse, lot en retard. Chacune n'est cherchée que si elle est active.
     *
     * @param  array<string, array<string, mixed>>  $cfg
     * @return list<array{event: string, key: string, title: string, body: string, href: string}>
     */
    private function business(Company $company, CarbonImmutable $now, array $cfg): array
    {
        $out = [];
        $money = fn (int|float $v) => number_format(abs((int) round($v)), 0, ',', ' ').' F';
        $today = CarbonImmutable::parse($now->toDateString());
        $monthStart = $today->startOfMonth();

        // Forfait dépassé sur le mois en cours
        if ($cfg['forfait_over']['active']) {
            foreach (BillingPlan::forCompany($company->id)->where('formula', '!=', BillingFormula::DistributionLot->value)->where('included_quantity', '>', 0)
                ->overlapping($monthStart->toDateString(), $today->toDateString())->with('merchant:id,business_name')->get() as $plan) {
                $used = Order::withoutGlobalScopes()->where('company_id', $company->id)->where('billing_plan_id', $plan->id)
                    ->whereBetween('created_at', [$now->startOfMonth()->utc(), $now->utc()])
                    ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Rejected->value])->count();
                $usage = (int) round($used / $plan->included_quantity * 100);
                if ($usage > $cfg['forfait_over']['threshold']) {
                    $name = $plan->merchant?->business_name ?? 'Un client';
                    $out[] = $this->alert('forfait_over', "forfait:{$plan->id}:{$monthStart->format('Y-m')}", "Forfait dépassé : {$name}",
                        "{$name} a consommé {$usage} % de son forfait ce mois-ci ({$used} sur {$plan->included_quantity} inclus)", '/admin/contrats');
                }
            }
        }

        // Factures en retard
        if ($cfg['invoice_overdue']['active']) {
            $days = (int) $cfg['invoice_overdue']['threshold'];
            $late = ClientInvoice::withoutGlobalScopes()->where('company_id', $company->id)->where('status', ClientInvoiceStatus::Issued->value)
                ->whereNotNull('due_at')->whereDate('due_at', '<', $today->subDays($days)->toDateString())->with('merchant:id,business_name')->get()
                ->filter(fn (ClientInvoice $i) => $i->balance() > 0)->groupBy('merchant_id');
            foreach ($late as $merchantId => $invoices) {
                $name = $invoices->first()->merchant?->business_name ?? 'Un client';
                $out[] = $this->alert('invoice_overdue', "invoice:{$merchantId}", "Facture en retard : {$name}",
                    "{$name} doit {$money($invoices->sum(fn (ClientInvoice $i) => $i->balance()))} sur {$invoices->count()} facture(s) en retard de plus de {$days} jours", '/admin/factures');
            }
        }

        // Contrats qui arrivent à échéance
        if ($cfg['contract_ending']['active']) {
            $days = (int) $cfg['contract_ending']['threshold'];
            foreach (BillingPlan::forCompany($company->id)->where('formula', '!=', BillingFormula::DistributionLot->value)->whereNotNull('ends_on')
                ->whereDate('ends_on', '>=', $today->toDateString())->whereDate('ends_on', '<=', $today->addDays($days)->toDateString())->with('merchant:id,business_name')->get() as $plan) {
                $name = $plan->merchant?->business_name ?? 'Un client';
                $out[] = $this->alert('contract_ending', "contract:{$plan->id}", "Contrat à renouveler : {$name}",
                    "le contrat {$plan->formula->label()} de {$name} se termine le {$plan->ends_on->format('d/m/Y')}", '/admin/contrats');
            }
        }

        // Objectifs du mois en retard, à partir du jour choisi
        if ($cfg['objective_behind']['active'] && $today->day >= (int) $cfg['objective_behind']['threshold']) {
            foreach (app(DirectionObjectives::class)->build($company, $monthStart, Activity::cases())['groups'] as $group) {
                $behind = collect($group['rows'])->where('status', 'behind');
                if ($behind->isNotEmpty()) {
                    $out[] = $this->alert('objective_behind', "objective:{$group['scope']}:{$monthStart->format('Y-m')}", "Objectifs en retard : {$group['label']}",
                        "{$group['label']} : {$behind->count()} objectif(s) du mois en retard ({$behind->pluck('label')->implode(', ')})", '/admin/statistiques?vue=objectives');
                }
            }
        }

        // Écarts de caisse des 7 derniers jours
        if ($cfg['cash_gap']['active']) {
            $gaps = CourierRemittance::forCompany($company->id)->whereNull('cancelled_at')->where('difference', '<', 0)
                ->whereBetween('received_at', [$now->subDays(7)->utc(), $now->utc()])->selectRaw('courier_id, SUM(difference) AS total')->groupBy('courier_id')->pluck('total', 'courier_id');
            $names = Courier::withoutGlobalScopes()->whereIn('id', $gaps->keys())->with('user:id,name')->get()->mapWithKeys(fn ($c) => [$c->id => $c->user?->name]);
            foreach ($gaps as $courierId => $total) {
                if (abs((int) $total) >= (int) $cfg['cash_gap']['threshold']) {
                    $name = $names[$courierId] ?? 'Un livreur';
                    $out[] = $this->alert('cash_gap', "cash:{$courierId}", "Écart de caisse : {$name}", "{$name} a {$money($total)} d'écart de caisse sur 7 jours", '/admin/caisse');
                }
            }
        }

        // Lots qui ont dépassé leur échéance sans être terminés
        if ($cfg['lot_late']['active']) {
            foreach (BillingPlan::forCompany($company->id)->lots()->whereNotNull('lot_due_on')->whereDate('lot_due_on', '<', $today->toDateString())
                ->whereDate('lot_due_on', '>=', $today->subDays(30)->toDateString())->with('merchant:id,business_name')->get() as $lot) {
                $delivered = Order::withoutGlobalScopes()->where('company_id', $company->id)->where('billing_plan_id', $lot->id)->where('status', OrderStatus::Delivered->value)->count();
                if ($delivered < (int) $lot->lot_quantity) {
                    $out[] = $this->alert('lot_late', "lot:{$lot->id}", "Lot en retard : {$lot->lotName()}",
                        "{$lot->lotName()} ({$lot->merchant?->business_name}) : {$delivered} livrés sur {$lot->lot_quantity}, échéance du {$lot->lot_due_on->format('d/m/Y')} dépassée", '/admin/statistiques?vue=distribution');
                }
            }
        }

        return $out;
    }

    /** @return array{event: string, key: string, title: string, body: string, href: string} */
    private function alert(string $event, string $key, string $title, string $body, string $href): array
    {
        return compact('event', 'key', 'title', 'body', 'href');
    }

    private function cacheKey(Company $company, string $key): string
    {
        return "stats-alert:{$company->id}:{$key}";
    }
}
