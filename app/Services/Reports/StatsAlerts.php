<?php

namespace App\Services\Reports;

use App\Enums\CompanyStatus;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\WhatsAppTemplate;
use App\Models\Company;
use App\Models\Courier;
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
use Illuminate\Support\Facades\Notification;

/**
 * Alertes statistiques du matin (réglages : Statistiques › Objectifs › Alertes), aux administrateurs :
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
     * @return list<array{key: string, title: string, body: string, href: string}>
     */
    public function send(Company $company, ?CarbonImmutable $now = null): array
    {
        $alerts = collect($this->detect($company, $now))->reject(fn (array $a) => Cache::has($this->cacheKey($company, $a['key'])))->values();
        if ($alerts->isEmpty()) {
            return [];
        }
        $admins = User::query()->where('company_id', $company->id)->whereNull('merchant_id')->where('status', 'active')->role(Role::Admin->value)->get();
        $whatsapp = $this->settings->for($company)['alerts']['whatsapp'];
        foreach ($alerts as $a) {
            Cache::put($this->cacheKey($company, $a['key']), true, now()->addDays(7));
            Notification::send($admins, new StatsAlertRaised($a['key'], $a['title'], $a['body'], $a['href']));
            if ($whatsapp) {
                foreach ($admins as $admin) {
                    $this->messenger->whatsapp($company->id, $admin->phone, 'staff', WhatsAppTemplate::StatsAlert,
                        [strtok($admin->name, ' ') ?: $admin->name, $company->name, $a['body'], $company->url($a['href'])], ['event' => 'stats.alert']);
                }
            }
        }

        return $alerts->all();
    }

    /**
     * @return list<array{key: string, title: string, body: string, href: string}>
     */
    public function detect(Company $company, ?CarbonImmutable $now = null): array
    {
        $tz = $company->timezone ?: (string) config('app.timezone', 'UTC');
        $now ??= CarbonImmutable::now($tz);
        $s = $this->settings->for($company)['alerts'];
        $min = (int) $s['min_volume'];
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
        if ($s['zone_success_min'] !== null) {
            $ok = $weekDelivered->groupBy('delivery_zone_id')->map->count();
            $ko = $week->groupBy(fn ($e) => $e->order?->delivery_zone_id)->map->count();
            $names = Zone::withoutGlobalScopes()->whereIn('id', $ok->keys()->merge($ko->keys())->filter())->pluck('name', 'id');
            foreach ($ok->keys()->merge($ko->keys())->unique()->filter() as $zone) {
                $total = ($ok[$zone] ?? 0) + ($ko[$zone] ?? 0);
                $rate = $total ? round(($ok[$zone] ?? 0) / $total * 100) : 100;
                if ($total >= $min && $rate < $s['zone_success_min']) {
                    $out[] = $this->alert("zone:{$zone}", "📍 Réussite en baisse : {$names[$zone]}",
                        "taux de réussite de {$names[$zone]} à {$rate} % sur 7 jours (seuil {$s['zone_success_min']} %, {$total} passages)", '/admin/statistiques?onglet=quality&periode=week');
                }
            }
        }

        // Livreurs dont les échecs doublent
        if ($s['courier_failures_double']) {
            $courierOf = fn ($e) => $e->assignment?->courier_id ?? $e->order?->delivery_courier_id;
            $now7 = $week->groupBy($courierOf)->map->count();
            $before = $failures($prevStart, $weekStart)->groupBy($courierOf)->map->count();
            $names = Courier::withoutGlobalScopes()->whereIn('id', $now7->keys()->filter())->with('user:id,name')->get()->mapWithKeys(fn ($c) => [$c->id => $c->user?->name]);
            foreach ($now7 as $courier => $count) {
                $prev = (int) ($before[$courier] ?? 0);
                if ($courier && $count >= max(3, intdiv($min, 2)) && $count >= 2 * max(1, $prev)) {
                    $name = $names[$courier] ?? 'Un livreur';
                    $out[] = $this->alert("courier:{$courier}", "🛵 Échecs en hausse : {$name}",
                        "{$name} a {$count} échecs sur 7 jours contre {$prev} la semaine d'avant", '/admin/statistiques?onglet=quality&periode=week');
                }
            }
        }

        // Clients en baisse
        if ($s['client_drop_percent'] !== null) {
            $orders = fn (CarbonImmutable $from, CarbonImmutable $to) => Order::withoutGlobalScopes()->where('company_id', $company->id)
                ->whereBetween('created_at', [$from->utc(), $to->utc()])->selectRaw('merchant_id, COUNT(*) AS n')->groupBy('merchant_id')->pluck('n', 'merchant_id');
            $last = $orders($now->subDays(30), $now);
            $before = $orders($now->subDays(120), $now->subDays(30));
            $names = Merchant::withoutGlobalScopes()->whereIn('id', $before->keys())->pluck('business_name', 'id');
            foreach ($before as $merchant => $n) {
                $avg = $n / 3;
                $recent = (int) ($last[$merchant] ?? 0);
                $drop = $avg > 0 ? (int) round((1 - $recent / $avg) * 100) : 0;
                if ($avg >= $min && $drop >= $s['client_drop_percent']) {
                    $out[] = $this->alert("client:{$merchant}", "🏪 Client en baisse : {$names[$merchant]}",
                        "{$names[$merchant]} a envoyé {$recent} courses en 30 jours contre ".(int) round($avg)." par mois avant (−{$drop} %)", '/admin/statistiques?onglet=merchants&periode=days30');
                }
            }
        }

        return $out;
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

        return DailySnapshot::withoutGlobalScopes()->updateOrCreate(['company_id' => $company->id, 'day' => $now->toDateString()], ['data' => [
            'untreated' => $orders()->untreated($company, $now->toDateString())->count(),
            'internal_late' => $orders()->whereNotNull('internal_due_at')->where('internal_due_at', '<=', $now->utc())->whereNotIn('status', Order::finalStatuses())->count(),
            'unassigned' => AwaitingCourier::scope($orders(), null, $now->toDateString())->count(),
            'delivered' => $orders()->where('status', OrderStatus::Delivered->value)->whereBetween('delivered_at', $day)->count(),
            'created' => $orders()->whereBetween('created_at', $day)->count(),
        ]]);
    }

    /** @return array{key: string, title: string, body: string, href: string} */
    private function alert(string $key, string $title, string $body, string $href): array
    {
        return compact('key', 'title', 'body', 'href');
    }

    private function cacheKey(Company $company, string $key): string
    {
        return "stats-alert:{$company->id}:{$key}";
    }
}
